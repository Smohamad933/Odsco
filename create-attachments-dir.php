<?php
$dir = 'uploads/attachments';
if (!file_exists($dir)) {
    mkdir($dir, 0755, true);
    echo "✅ پوشه ساخته شد";
} else {
    echo "پوشه از قبل وجود دارد";
}
?>