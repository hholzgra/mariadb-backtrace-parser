<html>
 <head>
  <title>GDB backtrace parser</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/themes/default/style.min.css" />
  <style>
.raw {
     background-color: lightblue;
}

/*
the following is needed to allow for multi line content jstree nodes

see also:
 https://stackoverflow.com/questions/24746781/how-do-i-get-a-jstree-node-to-display-long-possibly-multiline-content
*/

.jstree-default a {
     white-space:normal !important;
     height: auto;
}
.jstree-anchor {
     height: auto !important;
}
     .jstree-default li > ins {
     vertical-align:top;
}
.jstree-leaf {
     height: auto;
}
.jstree-leaf a{
     height: auto !important;
}
  </style>
 </head>
 <body>
     <?php
     if (!process_upload()) {
	 show_upload_form();
     }
     ?>

     <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.12.1/jquery.min.js"></script>
     <script src="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/jstree.min.js"></script>
     <script>
      $(function () {
	  $('#by_id').jstree();
	  $('#by_group').jstree();
	  $('#by_func').jstree();
      });
     </script>

 </body>
</html>


<?php
ini_set("display_errors", 1);

function show_upload_form() {
?>
    <h1>GDB log parser</h1>

    <p>
	Parses output of <tt style='background-color: lightblue'>gdb&gt; thread apply bt all</tt>
	from either a <tt>core</tt> file or a running MariaDB/MySQL server instance .
    </p>

    <p>
	You can either upload your own <tt>gdb</tt> log file, or just press the [Send file] button
	without selecting any file at all to see a demo.

	<form enctype="multipart/form-data" method="POST">
	    GDB backtrace: <input name="gdb" type="file" />
	    <br/>
	    <input type="submit" value="Send File" />
	</form>
    </p>
<?php
}

function process_upload()
{
    if (empty($_FILES) && empty($_GET)) {
	return false;
    }

    // take uploaded file, or bundled "gdb.log" example if no file was selected
    $gdbfile = empty($_FILES['gdb']['tmp_name']) ? "examples/gdb.log" : $_FILES['gdb']['tmp_name'];

    if (!is_readable($gdbfile)) {
	echo "can't open file '$gdbfile'";
    } else {
	$result = gdb_parse($gdbfile);
	if ($result !== false) {
	    show_result($result,  empty($_FILES['gdb']['name']) ? "examples/gdb.log" : $_FILES['gdb']['name']);
	} else {
	    echo "no parse result :(";
	}
    }

    return true;
}

// these lists are global as I am lazy
$threads = [];
$thread_groups = [];
$last_funcs = [];

function gdb_parse($gdbfile)
{
    global $threads;
    global $threads_groups;

    $fp = fopen($gdbfile, 'r');

    if (!$fp) {
	echo "can't open file '$gdbfile)";
	return false;
    }

    $lineno = 0;
    $current_thread = null;

    while (!feof($fp)) {
	$line = trim(fgets($fp));
	$lineno++;
	if ($line == "") continue;

	// seeing a gdb prompt? -> we're done
	if ($current_thread && ('(gdb)' == substr($line, 0 , 5))) break;

	// start of a new thread block?
	if (preg_match('|^Thread (\d+) \(Thread (\w+) \(LWP (\d+)\)\)|', $line, $m)) {
	    // store previous thread block parse results if any
	    if (isset($current_thread)) {
		store_thread($current_thread);
	    }

	    // create new thread block parse result
	    $current_thread = [
		'thread_id'   => $m[1],
		'thread_ptr'  => $m[2],
		'LWP'         => $m[3],
		'raw_content' => '',
		'functions'   => [],
		'group'       => '*unknown*',
		'type'        => '*unknown*',
	    ];
	} else if (isset($current_thread)) {
	    $current_thread['raw_content'] .= "$line\n";

	    // check for call stack frame lines
	    if (preg_match('|^#(\d+)\s(.*)$|', $line, $m)) {
		$frame = [
		    'frame' => $m[1],
		];

		$payload  = trim($m[2]);

		if (preg_match('|^(\w+) in (.*)|', $payload, $m)){
		    // frame may start with an optional address pointer
		    $frame['addr'] = $m[1];
		    $payload       = trim($m[2]);
		}

		if ($payload == '<signal handler called>') {
		    // special case of a function call
		    $frame['function'] = '***signal handler called***';
		} else if (preg_match('|^([\w@\.\?:]+) \((.*)\)(.*)$|', $payload, $matches)) {
		    // a regular function call with optional parameters
		    $frame['function'] = $matches[1];
		    $frame['params']   = parse_params($matches[2]);
		    $rest_of_line      = trim($matches[3]);

		    // check for specific known functions
		    handle_function_context($frame, $current_thread);

		    // find source code reference

		    // rest of line starts with "from": function from an external library
		    if (preg_match('|^from (.*)$|', $rest_of_line, $matches)) {
			$frame['source'] = $matches[1];
		    }

		    // rest of line starts with "at": function from known source file
		    if (preg_match('|^at (.*)$|', $rest_of_line, $matches)) {
			$where = trim($matches[1]);

			// try to shorten absolute paths to server source code
			if ($where[0] == '/') {
			    $where = preg_replace('|^/.*/mysql[^/]+/|',   '.../', $where);
			    $where = preg_replace('|^/.*/mariadb[^/]+/|', '.../', $where);
			    $where = preg_replace('|^/.*/build[^/]*/|',   '.../', $where);
			}

			$frame['source'] = $where;
		    }

		    // add extracted information to current threads function list
		    $current_thread['functions'][$frame['function']] = $frame;
		}
            }
        }
    }

    fclose($fp);

    // store final thread block parse results if any
    if (isset($current_thread)) {
	store_thread($current_thread);
    }

    // sort threads by thread number key
    ksort($threads);

    return (count($threads) > 0) ? $threads : false;
}

function show_result($result, $gdbfile)
{
    echo "GDB file: $gdbfile<hr/>\n";
    show_result_by_thread_id($result);
    echo "<hr/>\n";
    show_result_by_thread_group($result);
    echo "<hr/>\n";
    show_result_by_last_funcs($result);
}

function show_result_by_thread_id($result) {
    echo "  <h2>Threads by ID</h2>\n";
    echo "  <div id='by_id'>\n";
    echo "   <ul><li>Threads by ID (".count($result).")<ul>\n";
    foreach($result as $thread_id => $thread) {
        echo "   <li>\n";
        echo "    Thread $thread_id\n";
	thread_details($thread_id);
        echo "   </li>\n";
    }
    echo "   </ul></li></ul>\n";
    echo "  </div>\n";
}

function show_result_by_thread_group($result)
{
    global $threads, $thread_groups;

    echo "<h2>Threads by group</h2>\n<div id='by_group'><ul>\n";

    foreach ($thread_groups as $group => $types) {
	echo "<li>\n";

	if (count($types) > 1) {
	    echo "$group (".count($types).")<br/><ul>";

	    foreach ($types as $type => $members) {
		echo "<li>$type (".count($members).")<ul>\n";

		foreach ($members as $thread) {
		    show_member($thread);
		}
		echo "</ul></li>\n";
	    }
	    echo "</ul>\n";
	} else {
	    foreach ($types as $type => $members) { // there is only one
		echo "$group - $type (".count($members).")<ul>\n";

		foreach ($members as $thread) {
		    show_member($thread);
		}
		echo "</ul>\n";
	    }
	}

	echo "</li>\n";
    }

    echo "</ul></div>\n";
}

function _fnc_cnt_cmp($a, $b)
{
    $ca = count($a);
    $cb = count($b);

    if ($ca == $cb) {
        return 0;
    }
    return ($ca > $cb) ? -1 : 1;
}

function show_result_by_last_funcs($result)
{
    // TODO: order by count, descending
    global $threads;
    global $last_funcs;

    uasort($last_funcs, "_fnc_cnt_cmp");
    
    $n=0;

    echo "<h2>Threads by last function (".count($last_funcs).")</h2><div id='by_func'><ul>\n";

    foreach ($last_funcs as $func => $members) {
	echo "<li>\n";
	echo "<tt>$func</tt> (".count($members).")<br/><ul>";
	
	foreach ($members as $thread) {
	    $group = $threads[$thread]['group'];
	    $type  = $threads[$thread]['type'];
	    echo "<li>$group: $type ($thread)";
	    thread_details($thread);
	    echo "</li>\n";
	}
	
	echo "</ul></li>\n";
    }

    echo "</ul></div>";
    echo "<hr>\n";
}

function show_member($thread) {
    global $threads;
    echo "<li>Thread $thread";

    if (isset($threads[$thread]['query'])) {
	$query = $threads[$thread]['query'];

	$query = str_replace('\n', ' ', $query);
	if (strlen($query) > 60) {
	    $query = substr($query, 0, 60);
	    $query.= "[...]";
	}
	echo "&nbsp;<span style='background-color: lightblue'><tt>$query</tt></span>";
    }

    thread_details($thread);
    echo "</li>\n";
}

function store_thread($current_thread)
{
    global $threads;
    global $thread_groups;
    global $last_funcs;
    
    // add thread to threads list
    $threads[$current_thread['thread_id']] = $current_thread;

    // create thread group if not alreay created
    if (!isset($thread_groups[$current_thread['group']])) {
        $thread_groups[$current_thread['group']] = [];
    }
    // create thread type in group if not already exists
    if (!isset($thread_groups[$current_thread['group']][$current_thread['type']])) {
        $thread_groups[$current_thread['group']][$current_thread['type']] = [];
    }

    // add thread to thread group
    $thread_groups[$current_thread['group']][$current_thread['type']][] = $current_thread['thread_id'];

    $last_func = current(array_keys($current_thread['functions']));
    if (!isset($last_funcs[$last_func])) {
	$last_funcs[$last_func] = [];    
    }
    $last_funcs[$last_func][] = $current_thread['thread_id'];
}

function parse_params($params) {
    $in_quotes = false;
    $in_fptr   = false;

    $str = '';

    $results = [];

    // split parameter list by ','
    // but ignore commas inside of quoted strings or function prototypes inside <>
    for($i = 0; $i < strlen($params); $i++) {
        $c = $params[$i];

        switch ($c) {
            case '\\':
		$str .= $c;
		$str .= $params[++$i];
		break;
            case '"':
		$str .= $c;
		$in_quotes = !$in_quotes;
		break;
            case '<':
		$str .= $c;
		if (!$in_quotes) {
                    $in_fptr = true;
		}
		break;
            case '>':
		$str .= $c;
		if (!$in_quotes) {
                    $in_fptr = false;
		}
		break;
            case ',':
		if (!$in_quotes && !$in_fptr) {
                    $results[] = trim($str);
                    $str = '';
		} else {
                    $str .= $c;
		}
		break;
            default:
		$str .= $c;
		break;
        }
    }

    // store final parameter
    if (trim($str) != '') {
        $results[] = trim($str);
    }

    // now split up found parameters into name->value pairs
    $real_results = [];
    foreach ($results as $result) {
        unset($value);
        // split by first '=' only
        list($name, $value) = explode('=', $result, 2);

        // check if gdb also added extra entry value info and remove it
        $prefix = "$name@entry=";
        $prefix_len = strlen($prefix);
        if (substr($value, 0, $prefix_len) == $prefix) {
            $value = substr($value, $prefix_len);
        }

        $real_results[$name] = $value;
    }

    return $real_results;
}

function handle_function_context(&$function, &$thread) {
    switch ($function['function']) {
	    /*
	       the dispatch_command() function has the actual SQL query text
	       in its 'packet' parameter
	     */
	case 'dispatch_command':
            if (isset($function['params']['packet'])) {
		$query = clean_string($function['params']['packet']);
		$query = str_replace("\\n","\n", $query);
		$thread["query"] = $query;
            }
            break;

	    /*********************
	       Server Threads
	     ******************** */

	case 'mysqld_main':
            $thread['group'] = 'Server';
            $thread['type']  = 'main';
            break;

	case 'signal_hand':
            $thread['group'] = 'Server';
            $thread['type']  = 'signal handler';
            break;

	case 'timer_handler':
            $thread['group'] = 'Server';
            $thread['type']  = 'timer handler';
            break;

	    /*********************
	       Replication Threads
	     ******************** */

	case 'binlog_background_thread':
            $thread['group'] = 'Replication';
            $thread['type']  = 'binlog background';
            break;

	case 'handle_slave_background':
            $thread['group'] = 'Replication';
            $thread['type']  = 'slave background';
            break;

	    /*********************
	       Connection Thread
	     ******************** */

	case 'handle_one_connection':
            $thread['group'] = 'Connections';
            $thread['type']  = 'client';
            break;

	    /*********************
	       Aria Engine Threads
	     ******************** */

	case 'ma_checkpoint_background':
            $thread['group'] = 'Aria';
            $thread['type']  = 'checkpoint';
            break;

	    /*********************
	       InnoDB Engine Threads
	     ******************** */

	case 'btr_defragment_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'defragmentation';
            break;

	case 'buf_dump_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'buffer dump';
            break;

	case 'buf_flush_page_cleaner_coordinator':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'page cleaner coordinator';
            break;

	case 'buf_flush_page_cleaner_worker':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'page cleaner worker';
            break;

	case 'buf_resize_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'buffer pool resize';
            break;

	case 'dict_stats_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'dict stats';
            break;

	case 'fts_optimize_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'fulltext optimize';
            break;

	case 'io_handler_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'file IO';
            break;

	case 'lock_wait_timeout_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'lock wait timeout';
            break;

	case 'srv_error_monitor_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'error monitor';
            break;

	case 'srv_master_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'master';
            break;

	case 'srv_monitor_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'monitor';
            break;

	case 'srv_purge_coordinator_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'purge coordinator';
            break;

	case 'thd_destructor_proxy':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'THD destructor proxy';
            break;

	case 'srv_worker_thread':
            $thread['group'] = 'InnoDB';
            $thread['type']  = 'worker';
            break;
    }
}


function clean_string($string) {
    // extract raw string from gdb string parameter format
    $i1 = strpos($string, '"');
    $i2 = strrpos($string, '"');
    $string2 = substr($string, $i1+1, $i2 - $i1 -1);
    if (substr($string, $i2+1, 3) == "...") {
        $string2 .= " [...]";
    }

    return $string2;
}


function thread_details($thread_id)
{
    global $threads;

    $thread = $threads[$thread_id];

    echo "    <ul>\n";
    echo "     <li>Group: $thread[group]</li>\n";
    echo "     <li>Type: $thread[type]</li>\n";
    echo "     <li>thread_ptr: $thread[thread_ptr]</li>\n";
    echo "     <li>LWP: $thread[LWP]</li>\n";
    echo "     <li>Functions:\n";
    echo "      <ul>\n";
    foreach ($thread['functions'] as $function) {
        echo "       <li>\n";
        if (isset($function['source'])) {
            echo "        <span title='$function[source]'>$function[function]</span>\n";
        } else {
            echo "        $function[function]\n";
        }
        echo "        <ul>\n";
        foreach ($function['params'] as $name => $value) {
            $name  = htmlspecialchars($name, ENT_NOQUOTES);
            $value = htmlspecialchars($value, ENT_NOQUOTES);
            echo "         <li>$name: $value</li>\n";
        }
        echo "        </ul>\n";
        echo "       </li>\n";
    }
    echo "      </ul>\n";
    echo "     </li>\n";
    if (isset($thread['query'])) {
        echo "      <li>Query: <div class='raw'>".nl2br(htmlentities($thread['query']))."</div></li>\n";
    }
    echo "    </ul>\n";
}
