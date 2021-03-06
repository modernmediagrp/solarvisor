#!/usr/bin/env php

<?php

exit( main( $argv ) );

/**
 * program main().
 */
function main( $argv ) {
    
    $params = get_params();
    $rc = check_params( $params );
    if( $rc != 0 ) {
        return $rc;
    }
    print_params( $params );
    
    setup_signal_handlers();
    run_main_loop( params_2_settings( $params ) );   
}

/**
 * prints settings at startup
 */
function print_params( $params ) {
    echo date('c') . " -- solarvisor starting with these settings:\n---\n";
    echo trim( json_encode( $params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "{}" );
    echo "\n---\n\n";
}

/**
 * convert params with hyphens to underscores.
 */
function params_2_settings( $params ) {
    $settings = [];
    foreach( $params as $k => $v ) {
        $k = str_replace( '-', '_', $k);
        $settings[$k] = $v;
    }
    return $settings;    
}

/**
 * main loop of program.  the guts.
 */
function run_main_loop( $settings ) {
    extract( $settings );

    $stops_today = 0;
    $today = date('Y-m-d');

    $proc = &proc::$proc;
    $cnt = 0;
    $last_stop_time = 0;
    while( true ) {
        $running = $proc ? $proc->status() : false;
        $batt_info = get_battery_info();
        $volts = @$batt_info['Output Voltage'];  // fixme:  normalize.
        $time = date('c');

        // reset daily stop counter on date change.
        if( date('Y-m-d') != $today ) {
            $stops_today = 0;
            $today = date('Y-m-d');
        }

        if( $cnt++ == 0 && $force_start ) {
           echo sprintf( "$time -- Forcing start because --force-start used.  starting\n" );
           $proc = new process($load_cmd, $log_file);
        }
        else if( !$running && $stops_today >= $max_stops ) {
            echo sprintf( "$time -- Daily stop limit (%s) reached. no more starts until tomorrow\n", $max_stops );
            sleep(60);
            continue;
        }
        else if( !$volts ) {
            $startafter = strtotime( $failsafe_start );
            $stopafter = strtotime( $failsafe_stop );
            $ctime = time();

            // handle case where stop time is before start time, eg start at 9 am and stop at 1am.
            if( $stopafter < $startafter ) {
                if( $ctime < $startafter ) {
                    $startafter -= 86400;
                }
                else {
                    $stopafter += 86400;
                }
            }
            echo sprintf( "$time -- Battery voltage not read. failsafe mode.\n", $volts );
            
            $in_window = between( $ctime, $startafter, $stopafter );
            if( !$running && $in_window ) {
                if( between( $last_stop_time, $startafter, $stopafter ) ) {
                     echo sprintf( "$time   -- not running, but already stopped within failsafe window. no action.\n" );
                }
                else {
                    echo sprintf( "$time   -- within failsafe operation window and service not running. starting\n" );
                    $proc = new process( $load_cmd, $log_file );
                }
            }
            else if( $running && !$in_window && $proc ) {
                echo sprintf( "$time   -- outside failsafe operation window and service is running. stopping pid %s\n", $proc->get_pid() );
                $stops_today ++;
                $last_stop_time = time();
                $rc = $proc->stop();
                echo " |- " . ($rc ? "Success" : "Failed") . "\n";
            }
            else {
                echo sprintf( "$time -- %s operation window and service %s running. No action taken.\n", $in_window ? 'In' : 'Outside', $running ? 'is' : 'is not' );
            }
        }
        else if( $running && $volts < $volts_min ) {
            echo sprintf( "$time -- Battery voltage is %s. (below $volts_min).  stopping service pid: %s\n", $volts, $proc->get_pid() ) ;
            $stops_today ++;
            $last_stop_time = time();
            $rc = $proc->stop();
            echo " |- " . ($rc ? "Success" : "Failed") . "\n";
        }
        else if( !$running && $volts >= $volts_start_min ) {
            echo sprintf( "$time -- Battery voltage is %s.  (above $volts_start_min).  starting service\n", $volts );
            $proc = new process( $load_cmd, $log_file );
            $last_stop_time = 0;  // reset.
        }
        else {
            echo sprintf( "$time -- Voltage: %s. Running: %s.  No change.  min/start v: %s/%s\n", 
                          $volts,
                          $running ? 'yes' : 'no',
                          $volts_min,
                          $volts_start_min );
        }
        sleep(60);
    } 
}

/**
 * retrieves CLI args
 */
function get_params() {
    
    $opt = getopt("ha:sp:", [ 'load-cmd:', 'force-start', 'nominal:',
                              'volts-min:', 'volts-start-min:', 'log-file:',
                              'failsafe-start:', 'failsafe-stop:',
                              'max-stops:'] );
                             
    $params['load-cmd'] = @$opt['load-cmd'];
    $params['force_start'] = isset( $opt['force-start'] );
    $params['nominal'] = @$opt['nominal'] ?: 48;
    $params['volts-min'] = @$opt['volts-min'];
    $params['volts-start-min'] = @$opt['volts-start-min'];
    $params['log-file'] = @$opt['log-file'] ?: '/dev/null';
    $params['failsafe-start'] = @$opt['failsafe-start'] ?: '10:30';
    $params['failsafe-stop'] = @$opt['failsafe-stop'] ?: '18:00';
    $params['max-stops'] = @$opt['max-stops'] ?: 2;
    
    return $params;
}

/**
 * validates CLI args, modifies as needed.
 * prints error message on any validation error.
 * returns 0 on success.  else program should exit.
 */
function check_params( &$params ) {
    
    if( isset($opt['h']) || !($params['load-cmd'] ) ){
        print_help();
        return -1;
    }
    
    $nominal = $params['nominal'];
    $nominal_limits = get_nominal_voltages( $params['nominal'] );
    
    if( !@$nominal_limits ) {
        if( $nominal != 'none' ) {
            echo sprintf( "warning: nominal voltage '%s' is unknown.  assuming --nominal=none\n", $nominal );
        }
        
        if( !$params['volts-min'] || !$params['volts-start-min'] ) {
            echo sprintf( "volts-min and volts-start-min are required when nominal=none.\n" );
            return 1;
        }
    }
    else {
        // this checks prevent conflicts between --nominal and [--volts-min, --min-start_volts]
        if( $params['volts-min'] && !between($params['volts-min'], $nominal_limits['range-min'], $nominal_limits['range-max'] )) {
            echo sprintf( "volts-min %s outside range for nominal voltage: $nominal\n", $params['volts-min'] );
            return 1;        
        }
        else if( $params['volts-start-min'] && !between($params['volts-start-min'], $nominal_limits['range-min'], $nominal_limits['range-max'] )) {
            echo sprintf( "volts-start-min %s outside range for nominal voltage: $nominal\n", $params['volts-start-min']);
            return 1;        
        }
        // use user-supplied values if supplied, else defaults for specified nominal voltage.
        $params['volts-min'] = @$params['volts-min'] ?: $nominal_limits['volts-min'];
        $params['volts-start-min'] = @$params['volts-start-min'] ?: $nominal_limits['volts-start-min'];
    }
    
    if( $params['volts-min'] >= $params['volts-start-min'] ) {
        echo sprintf( "volts-start-min (%s) must be greater than volts-min (%s)\n", $params['volts-start-min'], $params['volts-min']);
        return 1;
    }

    if( (int)@$params['max-stops'] <= 0  ) {
        echo sprintf( "max-stops must be an integer greater than 0.\n" );
        return 1;
    }

    $fs_start = @strtotime( $params['failsafe-start'] );
    $fs_stop = @strtotime( $params['failsafe-stop'] );
    
    if( !$fs_start  ) {
        echo sprintf( "Invalid value for --failsafe-start\n" );
        return 1;
    }
    if( !$fs_stop  ) {
        echo sprintf( "Invalid value for --failsafe-stop\n" );
        return 1;
    }
    
    $fs_start_len_ok = between( strlen( $params['failsafe-start'] ), 4, 5); 
    $fs_stop_len_ok = between( strlen( $params['failsafe-stop'] ), 4, 5); 
    if( abs( $fs_stop - time() ) > 86400 || abs( $fs_start - time() ) > 86400
        || !$fs_start_len_ok || !$fs_stop_len_ok ) {
        echo "failsafe-start time and failsafe-stop time must be specified as hours, eg: 09:00 or 18:00.\n";
        return 1;
    }
    
    if( $fs_stop <= $fs_start ) {
        echo "Warning: failsafe-stop time precedes failsafe-start time.  Assuming stop next day.\n";
    }
    
    return 0;
}

/**
 * setup signal handlers for CTRL-C, TERM
 * needed so we can kill load-cmd before shutdown.
 */
function setup_signal_handlers() {
    
    declare(ticks = 50);
    pcntl_signal(SIGTERM, 'shutdown_cb');
    pcntl_signal(SIGINT, 'shutdown_cb');
    pcntl_signal(SIGCHLD, SIG_IGN);
}

/**
 * Returns table of nominal voltages if volts is null.
 * Otherwise, returns voltage info for specific voltage, or null if not found.
 */
function get_nominal_voltages( $volts = null ) {
    
    static $nominal_volts;
    
    if( $nominal_volts ) {
        return $nominal_volts;
    }
    
    // I start with 48 nominal because that is why my system uses and it is
    // easiest for me to think in those numbers.  I then calculate defaults
    // for other nominal voltages from there.
    $volts_min_48 = 51;
    $volts_start_min_48 = 53;
    $range_min_48 = 40;
    $range_max_48 = 64;
    
    $volts_min_12 = $volts_min_48 / 4;
    $volts_start_min_12 = $volts_start_min_48 / 4;
    $range_min_12 = $range_min_48 / 4;
    $range_max_12 = $range_max_48 / 4;
    
    $nominal_volts = [12 => ['volts-min' => $volts_min_12,
                             'volts-start-min' => $volts_start_min_12,
                             'range-min' => $range_min_12,
                             'range-max' => $range_max_12
                            ],
                      24 => ['volts-min' => $volts_min_12 * 2,
                             'volts-start-min' => $volts_start_min_12 * 2,
                             'range-min' => $range_min_12 * 2,
                             'range-max' => $range_max_12 * 2
                            ],
                      36 => ['volts-min' => $volts_min_12 * 3,
                             'volts-start-min' => $volts_start_min_12 * 3,
                             'range-min' => $range_min_12 * 3,
                             'range-max' => $range_max_12 * 3
                            ],
                      48 => ['volts-min' => $volts_min_48,
                             'volts-start-min' => $volts_start_min_48,
                             'range-min' => $range_min_48,
                             'range-max' => $range_max_48
                            ],
                      72 => ['volts-min' => $volts_min_12 * 6,
                             'volts-start-min' => $volts_start_min_12 * 6,
                             'range-min' => $range_min_12 * 6,
                             'range-max' => $range_max_12 * 6
                            ],
                      ];
    
    return $volts ? @$nominal_volts[$volts] : $nominal_volts;
}

/**
 * an abstract class to avoid a global var.  todo:  refactor.
 * ( needed for signal handler callback )
 */
abstract class proc {
    static $proc = null;
}

/**
 * retrieves battery info.
 * @todo: should be abstracted to call various implementations and return
 *        normalized dataset.
 */
function get_battery_info() {

    $url = 'http://192.168.2.201/theblackboxproject/htdocs/real.php';
    
    $context = stream_context_create( [ 'http'=> [ 'timeout' => 15 ] ] );
    $buf = file_get_contents($url, false, $context);
    
    $lines = explode( "\n", $buf );
    $info = [];
    foreach( $lines as $line ) {
    $row = explode( '|', $line );
        if( count( $row ) >= 2 ) {
            $info[$row[0]] = $row[1];
        }       
    }
    return $info;
}

// handle kill child process when we are killed via SIGTERM or SIGINT
function shutdown_cb( $signo=null ) {
    // stop child process if we are terminated.
    // must call exit, else CTRL-C will not work.
    echo "\nin signal handler! got signal $signo \n";

    $proc = &proc::$proc;
    if( $proc && $proc->get_pid() ) {
        echo sprintf( "stopping child process.  pid=%s...\n", $proc->get_pid() );;
        $rc = $proc->stop();
        echo $rc ? "  success!\n" : "  failed. The process is still running, but I must go... \n";
    }

    echo "exiting!\n";
    exit(0);
}

/**
 * returns true if value is between lower and upper.
 */
function between( $v, $lower, $upper ) {
    return $v >= $lower && $v <= $upper;
}


/**
 * prints help / usage.
 */
function print_help() {

   echo <<< END
   
      solarvisor.php

   This script starts and stops processes according to battery level.

   Required:
    --load-cmd=<cmd>       cmd to start process.

   Options:

    --force-start           exec cmd initially irregardless.
    --nominal=<volts>       nominal system voltage. 
                               12,24,36,48,72, or 'none'.  default = 48
    --volts-min=<v>         minimum voltage before stopping load.
                               default = 51 unless --nominal is used.
    --volts-start-min=<v>   minimum voltage before starting load.
                               default = 53 unless --nominal is used.
    --failsafe-start=<t>    failsafe window start time.  default=10:30
    --failsafe-stop=<t>     failsafe window end time.    default=18:00
    --max-stops=<n>         maximum stops per day. default=2
    --log-file=<path>       path to send load-cmd output. default = /dev/null
    
    -h                      Print this help.

END;

}

/**
 * A class to start/stop/status external commands.
 * @compatibility: Linux only. (Windows does not work).
 * @author: Peec
 * heavily modified by danda.
 */
class process{
    private $pid;
    private $command;
    private $logpath = '/dev/null';

    public function __construct($cl=false, $logpath){
        $this->logpath = $logpath;
        if ($cl != false){
            $this->command = $cl;
            $this->run();
        }
    }
    private function run(){
//        $command = sprintf( 'nohup %s > %s 2>&1 & echo $!', escapeshellcmd($this->command), $this->logpath );
        $command = sprintf( '%s > %s 2>&1 & echo $!', escapeshellcmd($this->command), $this->logpath );
        // posix_setsid();
        exec($command ,$op);
        $this->pid = (int)$op[0];
        echo "pid = " . $this->pid . "\n";
    }

    public function set_pid($pid){
        $this->pid = $pid;
    }

    public function get_pid(){
        return $this->pid;
    }

    public function status( $pid = null ){
        $checkpid = $pid ?: $this->pid;
        if( !$checkpid ) {
            return false;
        }
        return posix_kill( $checkpid, 0 );
    }

    public function start(){
        if ($this->command != '') {
            $this->run();
        }
        return true;
    }

    public function stop(){
        if( !$this->pid ) {
            return false;
        }

        //use ps to get all the children of this process, and kill them
        $ppid = $this->pid;
        
        $rstop = function( $ppid ) use ( &$rstop ) {
            $pids = preg_split('/\s+/', `ps -o pid --no-heading --ppid $ppid`);
            $cpids = [];
            foreach($pids as $pid) {
                if(is_numeric($pid)) {
                    $cpids = $rstop( $pid );
                    echo " |- killing child pid: $pid\n";
                    if( !posix_kill($pid, SIGTERM) ) {
                       echo posix_strerror( posix_get_last_error() );
                    }
                }
            }
            return array_merge( $pids, $cpids );
        };
        $pids = $rstop( $this->pid );
        
        if( !posix_kill( $this->pid, SIGTERM ) ) {
           echo posix_strerror( posix_get_last_error() );
        }
        
        sleep( 3 );

        if ($this->status() == false) {
            $this->pid = null;
        }
        
        foreach($pids as $pid) {
            if( $this->status( $pid )) {
                return false;
            }
        }
        
        if (!$this->pid) {
            return true;
        }

        // hmm, no luck.  try again, but with kill -9 instead.
        if( !posix_kill( $this->pid, SIGKILL ) ) {
           echo posix_strerror( posix_get_last_error() );
        }

        sleep(1);

        if ($this->status() == false) {
            $this->pid = null;
            return true;
        }
        return false;
    }
}


