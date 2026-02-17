<?php
require_once '../shared/config.php';
session_destroy();
header("Location: login.php");
exit;
