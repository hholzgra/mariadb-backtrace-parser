# mariadb-backtrace-parser -  a GNU Debugger backtrace parser for MariaDB

Parse gdb "thread all apply bt" backtrace from MariaDB server

Analyzing "thread apply bt all" output (call stack traces for all threads) from GDB, when either attached to a running "mysqld" server or reading a post mortem core dump, is a tricky business that requires knowledge in both GDB and in MariaDB server internals. Finding the interesting bits can also be tedious and time consuming, especially when having to check more than just a single backtrace set.

This project has two aims:

* presenting GDB backtrace output and presenting it in a more usable way in a web interface
* being context aware by e.g. putting threads into thematic groups (Server, InnoDB, ...), labeling them by their actual responsibility instead of just an arbitrary thread number, extracing currently executed SQL query where possible, etc.


