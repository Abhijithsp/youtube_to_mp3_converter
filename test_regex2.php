<?php
preg_match_all('/\[download\]\s+([\d\.]+)%\s+of\s+~?\s*([\d\.]+[KMG]iB|[\d\.]+B)/', "[download]   0.1% of    1.20MiB at   49.65KiB/s ETA 00:24\n[download]  50.0% of ~5.00MiB at  1.00MiB/s ETA 00:05", $matches);
print_r($matches);
