<?php
session_start();
session_destroy();
header("Location: login.php?success=logged_out");
exit();
?>