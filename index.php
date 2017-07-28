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
if (empty($_FILES)) {
    // nothing uploaded yet -> show upload form and instructions
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
<?php
} else {
    // take uploaded file, or bundled "gdb.log" example if no file was selected
    $gdbfile = $_FILES['gdb']['tmp_name'] ?: "./gdb.log";

    if (!is_readable($gdbfile)) {
        echo "can't open file '$gdbfile'";
    } else {
        $result = gdb_parse($gdbfile);
        if ($result !== false) {
            show_result($result);
        }
    }
}
?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.12.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/jstree.min.js"></script>
  <script>
  $(function () {
    $('#by_id').jstree();
  });
  </script>

 </body>
</html>
<?php
function gdb_parse($gdbfile) {
    $threads = [];
    $current_thread = null;
    
    $fp = fopen($gdbfile, 'r');

    while (!feof($fp)) {
        $line = trim(fgets($fp));
        if ($line == "") continue;

        // seeing a gdb prompt? -> we're done
        if ($current_thread && ('(gdb)' == substr($line, 0 , 5))) break;

        // start of a new thread block?
        if (preg_match('|^Thread (\d+) \(Thread (\w+) \(LWP (\d+)\)\)|', $line, $m)) {
            // store previous thread block parse results if any
            if (isset($current_thread)) {
                $threads[$current_thread['thread_id']] = $current_thread;
            }

            // create new thread blog parse result
            $current_thread = [
                'thread_id'   => $m[1],
                'thread_ptr'  => $m[2],
                'LWP'         => $m[3],
                'raw_content' => '',
                'functions'   => [],
            ];
        } else if (isset($current_thread)) {
            $current_thread['raw_content'] .= "$line\n";

            // check for call stack frame lines
            if (preg_match('|^#(\d+)\s(.*)$|', $line, $m)) {
                $frame = [
                    'frame' => $m[1],
                ];

                $payload  = trim($m[2]);

                // frame starts with an address pointer (optional)
                if (preg_match('|^(\w+) in (.*)|', $payload, $m)){
                    $frame['addr'] = $m[1];
                    $payload       = trim($m[2]);
                }

                if ($payload == '<signal handler called>') {
                    // special case of a function call
                    $frame['function'] = '***signal handler called***';
                } else if (preg_match('|^([\w@\.\?:]+) \((.*)\)(.*)$|', $payload, $m)) {
                    // a regular function call with optional parameters
                    $frame['function'] = $m[1];
                    $frame['params']   = parse_params($m[2]);

                    $rest = trim($m[3]);

                    // function from an external library
                    if (preg_match('|^from (.*)$|', $rest, $m)) {
                        $frame['source'] = $m[1];
                    }

                    // function from known source file
                    if (preg_match('|^at (.*)$|', $rest, $m)) {
                        $where = trim($m[1]);

                        // try to shorten absolute paths to server source code
                        if ($where[0] == '/') {
                            $where = preg_replace('|^/.*/mysql[^/]+/|',   '.../', $where);
                            $where = preg_replace('|^/.*/mariadb[^/]+/|', '.../', $where);
                            $where = preg_replace('|^/.*/build[^/]*/|',   '.../', $where);
                        }

                        $frame['source'] = $where;
                    }

                    $current_thread['functions'][$frame['function']] = $frame;
                }
            }
        }
    }

    fclose($fp);

    // store final thread block parse results if any
    if (isset($current_thread)) {
        $threads[$current_thread['thread_id']] = $current_thread;
    }

    ksort($threads);

    return count($threads) > 0 ? $threads : false;
}

function show_result($result) {
    echo "  <h2>Threads by ID</h2>\n";
    echo "  <div id='by_id'>\n";
    echo "   <ul><li>Threads by ID<ul>\n";
    foreach($result as $thread_id => $thread) {
        echo "   <li>\n";
        echo "    Thread $thread_id\n";
        echo "    <ul>\n";
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
        echo "    </ul>\n";
        echo "   </li>\n";
    }
    echo "   </ul></li></ul>\n";
    echo "  </div>\n";
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
