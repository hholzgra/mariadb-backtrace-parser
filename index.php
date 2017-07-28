<html>
 <head>
  <title>GDB backtrace parser</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/themes/default/style.min.css" />
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
  });
  </script>

 </body>
</html>
<?php
function gdb_parse($gdbfile) {
    return file_get_contents($gdbfile);
}

function show_result($result) {
    echo "<pre>$result</pre>";
}