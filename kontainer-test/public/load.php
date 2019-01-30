<?php

//echo shell_exec('/usr/bin/stress -c 1 -t 10 -m 1');
$count = 1;
for($i=1; $i<=100000000; $i++) {
  $count = $count + 1;
  $sqrRt = sqrt($count);
  echo '';
}

echo "Looped with maths $count times\n";

?>