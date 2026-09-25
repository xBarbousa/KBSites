<?php
// The affiliate area moved into the unified account portal. Keep old links working.
$q = isset($_GET['view']) && $_GET['view']==='agreement' ? '?view=agreement' : '?view=partner';
header('Location: ../account/' . $q, true, 301);
exit;
