<?php
// DEV deployment keeps WordPress closed until the complete Git artifact is installed.
// Re-evaluate at each request so slow FTP uploads do not expire maintenance midway.
$upgrading = time();
