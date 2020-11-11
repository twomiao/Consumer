<?php


$taks = 0;

while(1)
{
    echo $taks.PHP_EOL;

    if (++$taks === 10) {
        break;
    }
}

exit;