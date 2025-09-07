<?php
if($_POST) {
    $email = $_POST['email'];
    $file = fopen("subscribers.txt", "a");
    fwrite($file, $email . "\n");
    fclose($file);
    echo "Thank you for subscribing!";
}
?>
