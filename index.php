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
            ];
        } else if (isset($current_thread)) {
            $current_thread['raw_content'] .= "$line\n";
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
        echo "     <li><div class='raw'><pre>$thread[raw_content]</pre></div></li>\n";
        echo "    </ul>\n";
        echo "   </li>\n";
    }
    echo "   </ul></li></ul>\n";
    echo "  </div>\n";
}