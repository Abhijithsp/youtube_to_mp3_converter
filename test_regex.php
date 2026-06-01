<?php
$url = 'https://www.youtube.com/shorts/iKXh0Fgzau0';
$pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/';
preg_match($pattern, $url, $matches);
print_r($matches);
