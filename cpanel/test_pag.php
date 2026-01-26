<?php
$cases = [
    [0, 10],
    [5, 10],
    [10, 10],
    [11, 10],
    [20, 10],
    [21, 10]
];

foreach ($cases as $c) {
    list($total, $per) = $c;
    $pages = ceil($total / $per);
    echo "Total: $total | Per: $per | Pages (ceil): $pages\n";
}
