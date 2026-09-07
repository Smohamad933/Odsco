<?php
if (!isset($projectTitle)) $projectTitle = '';
if (!isset($client)) $client = '';
if (!isset($location)) $location = '';
if (!isset($area)) $area = '';
if (!isset($overview)) $overview = '';
if (!isset($specs)) $specs = [];
if (!isset($currentUrl)) $currentUrl = '';
if (!isset($instagramHashtags)) $instagramHashtags = "#معماری #طراحی";

if (!function_exists('fixImagePath')) {
    function fixImagePath($imgPath) {
        $imgPath = trim($imgPath);
        if (strpos($imgPath, 'http') === 0) return $imgPath;
        if (strpos($imgPath, 'assets/') === 0) return '../' . $imgPath;
        if (strpos($imgPath, '../assets/') === 0) return $imgPath;
        if (strpos($imgPath, 'uploads/') === 0) return '../' . $imgPath;
        if (strpos($imgPath, '../uploads/') === 0) return $imgPath;
        return '../assets/images/' . basename($imgPath);
    }
}

$safeProjectImages = [];
if (isset($projectImages) && is_array($projectImages) && !empty($projectImages)) {
    $safeProjectImages = $projectImages;
} elseif (isset($images) && is_array($images) && !empty($images)) {
    $safeProjectImages = $images;
} elseif (isset($project) && isset($project['images']) && is_array($project['images']) && !empty($project['images'])) {
    $safeProjectImages = $project['images'];
} elseif (isset($project) && !empty($project['cover_image'])) {
    $safeProjectImages[] = $project['cover_image'];
}
if (empty($safeProjectImages)) {
    $safeProjectImages[] = "../assets/default-project.jpg";
}
?>

<div class="instagram-box" id="storyBox">
    <p style="font-size: 13px; margin-bottom: 12px;">سازنده استوری:</p>
    <div class="image-thumbnails-grid" id="storyThumbnailsGrid">
        <?php foreach ($safeProjectImages as $index => $imgPath): $thumbImg = fixImagePath($imgPath); ?>
            <div class="thumb-item <?php echo $index === 0 ? 'selected' : ''; ?>" onclick="changeStoryImage('<?php echo htmlspecialchars($thumbImg, ENT_QUOTES); ?>', <?php echo $index; ?>, this)">
                <img src="<?php echo htmlspecialchars($thumbImg, ENT_QUOTES); ?>" alt="Thumb">
            </div>
        <?php endforeach; ?>
    </div>
    <div class="slide-config-bar">
        <label><input type="checkbox" id="storyShowDetails" onchange="toggleStoryDetails()" checked> نمایش مشخصات</label>
        <button type="button" class="action-btn" style="padding:4px 10px;font-size:11px;" onclick="resetStoryTransform()">🔄 ریست</button>
    </div>
    <div class="story-controls">
        <div class="control-group"><label>Zoom: <span id="sValZoom">100</span>%</label><input type="range" id="sRangeZoom" min="50" max="250" value="100" oninput="updateStorySettings()"></div>
        <div class="control-group"><label>X: <span id="sValPosX">0</span></label><input type="range" id="sRangePosX" min="-500" max="500" value="0" oninput="updateStorySettings()"></div>
        <div class="control-group"><label>Y: <span id="sValPosY">0</span></label><input type="range" id="sRangePosY" min="-500" max="500" value="0" oninput="updateStorySettings()"></div>
        <div class="control-group"><label>عنوان: <span id="sValTitle">52</span>px</label><input type="range" id="sRangeTitle" min="30" max="90" value="52" oninput="updateStorySettings()"></div>
        <div class="control-group"><label>QR: <span id="sValQr">140</span>px</label><input type="range" id="sRangeQr" min="0" max="220" value="140" oninput="updateStorySettings()"></div>
        <div class="control-group"><label>تیرگی: <span id="sValOpacity">90</span>%</label><input type="range" id="sRangeOpacity" min="30" max="100" value="90" oninput="updateStorySettings()"></div>
        <div class="control-group"><label>ارتفاع: <span id="sValGradHeight">50</span>%</label><input type="range" id="sRangeGradHeight" min="20" max="100" value="50" oninput="updateStorySettings()"></div>
    </div>
    <div class="story-preview-container" id="storyPreviewContainer"></div>
    <div class="story-actions-flex">
        <button type="button" class="action-btn" onclick="downloadCurrentStory()">📥 دانلود</button>
        <button type="button" class="action-btn" style="background:#28a745;" onclick="downloadAllStoriesZip()">📦 دانلود همه</button>
    </div>
    <textarea class="ig-caption-area" id="storyCaption" readonly><?php echo htmlspecialchars($projectTitle); ?> - <?php echo htmlspecialchars($location); ?>&#10;<?php echo $instagramHashtags; ?></textarea>
    <button class="action-btn" style="width:100%;" onclick="copyText('storyCaption')">📋 کپی</button>
</div>

<div class="instagram-box" id="postBox">
    <p style="font-size: 13px; margin-bottom: 12px;">سازنده پست:</p>
    <div class="image-thumbnails-grid" id="postThumbnailsGrid">
        <?php foreach ($safeProjectImages as $index => $imgPath): $thumbImg = fixImagePath($imgPath); ?>
            <div class="thumb-item <?php echo $index === 0 ? 'selected' : ''; ?>" onclick="changePostImage('<?php echo htmlspecialchars($thumbImg, ENT_QUOTES); ?>', <?php echo $index; ?>, this)">
                <img src="<?php echo htmlspecialchars($thumbImg, ENT_QUOTES); ?>" alt="Thumb">
            </div>
        <?php endforeach; ?>
    </div>
    <div class="slide-config-bar">
        <label><input type="checkbox" id="postShowDetails" onchange="togglePostDetails()" checked> نمایش مشخصات</label>
        <button type="button" class="action-btn" style="padding:4px 10px;font-size:11px;" onclick="resetPostTransform()">🔄 ریست</button>
    </div>
    <div class="story-controls">
        <div class="control-group"><label>Zoom: <span id="pValZoom">100</span>%</label><input type="range" id="pRangeZoom" min="50" max="250" value="100" oninput="updatePostSettings()"></div>
        <div class="control-group"><label>X: <span id="pValPosX">0</span></label><input type="range" id="pRangePosX" min="-500" max="500" value="0" oninput="updatePostSettings()"></div>
        <div class="control-group"><label>Y: <span id="pValPosY">0</span></label><input type="range" id="pRangePosY" min="-500" max="500" value="0" oninput="updatePostSettings()"></div>
        <div class="control-group"><label>عنوان: <span id="pValTitle">48</span>px</label><input type="range" id="pRangeTitle" min="30" max="80" value="48" oninput="updatePostSettings()"></div>
        <div class="control-group"><label>QR: <span id="pValQr">120</span>px</label><input type="range" id="pRangeQr" min="0" max="200" value="120" oninput="updatePostSettings()"></div>
        <div class="control-group"><label>تیرگی: <span id="pValOpacity">95</span>%</label><input type="range" id="pRangeOpacity" min="30" max="100" value="95" oninput="updatePostSettings()"></div>
        <div class="control-group"><label>ارتفاع: <span id="pValGradHeight">55</span>%</label><input type="range" id="pRangeGradHeight" min="20" max="100" value="55" oninput="updatePostSettings()"></div>
    </div>
    <div class="post-preview-container" id="postPreviewContainer"></div>
    <div class="story-actions-flex">
        <button type="button" class="action-btn" onclick="downloadCurrentPost()">📥 دانلود</button>
        <button type="button" class="action-btn" style="background:#28a745;" onclick="downloadAllPostsZip()">📦 دانلود همه</button>
    </div>
    <textarea class="ig-caption-area" id="postCaption" readonly>پروژه: <?php echo htmlspecialchars($projectTitle); ?>&#10;📍 <?php echo htmlspecialchars($location); ?>&#10;👤 <?php echo htmlspecialchars($client); ?>&#10;📐 <?php echo htmlspecialchars($area); ?>&#10;&#10;<?php echo htmlspecialchars($overview); ?>&#10;&#10;🌐 <?php echo $currentUrl; ?>&#10;&#10;<?php echo $instagramHashtags; ?></textarea>
    <button class="action-btn" style="width:100%;" onclick="copyText('postCaption')">📋 کپی</button>
</div>

<script>
const projectData = {
    title: <?php echo json_encode($projectTitle, JSON_UNESCAPED_UNICODE); ?>,
    location: <?php echo json_encode('موقعیت: ' . $location, JSON_UNESCAPED_UNICODE); ?>,
    url: <?php echo json_encode($currentUrl, JSON_UNESCAPED_UNICODE); ?>,
    specs: <?php echo json_encode(array_values($specs), JSON_UNESCAPED_UNICODE); ?>,
    images: <?php echo json_encode(array_map('fixImagePath', $safeProjectImages), JSON_UNESCAPED_UNICODE); ?>,
    storyIndex: 0,
    postIndex: 0,
    storySettings: {},
    postSettings: {}
};

projectData.images.forEach((img, idx) => {
    projectData.storySettings[idx] = { imageSrc: img, showDetails: true, zoom: 100, posX: 0, posY: 0, titleSize: 52, qrSize: 140, opacity: 90, gradHeight: 50 };
    projectData.postSettings[idx] = { imageSrc: img, showDetails: true, zoom: 100, posX: 0, posY: 0, titleSize: 48, qrSize: 120, opacity: 95, gradHeight: 55 };
});

function toggleBox(boxId) {
    const box = document.getElementById(boxId);
    const isOpen = box.classList.contains('active');
    document.getElementById('storyBox').classList.remove('active');
    document.getElementById('postBox').classList.remove('active');
    if (!isOpen) {
        box.classList.add('active');
        if (boxId === 'storyBox') loadStorySlideUI(projectData.storyIndex);
        else loadPostSlideUI(projectData.postIndex);
    }
}

function copyText(id) {
    const el = document.getElementById(id);
    el.select();
    document.execCommand('copy');
    alert('کپی شد!');
}

function drawQRCode(ctx, text, x, y, size) {
    if (size <= 0) return;
    try {
        const qr = qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        const cells = qr.getModuleCount();
        const cell = size / cells;
        ctx.fillStyle = '#fff';
        ctx.fillRect(x - 8, y - 8, size + 16, size + 16);
        ctx.fillStyle = '#000';
        for (let r = 0; r < cells; r++) {
            for (let c = 0; c < cells; c++) {
                if (qr.isDark(r, c)) ctx.fillRect(x + c * cell, y + r * cell, cell + 0.5, cell + 0.5);
            }
        }
    } catch(e) {}
}

function wrapText(ctx, text, x, y, maxWidth, lineHeight) {
    const words = text.split(' ');
    let line = '';
    const lines = [];
    for (let w of words) {
        const test = line + w + ' ';
        if (ctx.measureText(test).width > maxWidth && line) {
            lines.push(line.trim());
            line = w + ' ';
        } else {
            line = test;
        }
    }
    lines.push(line.trim());
    for (let i = lines.length - 1; i >= 0; i--) {
        ctx.fillText(lines[i], x, y);
        y -= lineHeight;
    }
}

function changeStoryImage(imgPath, index, element) {
    projectData.storyIndex = index;
    projectData.storySettings[index].imageSrc = imgPath;
    loadStorySlideUI(index);
    document.querySelectorAll('#storyThumbnailsGrid .thumb-item').forEach(i => i.classList.remove('selected'));
    element.classList.add('selected');
}

function loadStorySlideUI(index) {
    const s = projectData.storySettings[index];
    document.getElementById('storyShowDetails').checked = s.showDetails;
    document.getElementById('sRangeZoom').value = s.zoom;
    document.getElementById('sRangePosX').value = s.posX;
    document.getElementById('sRangePosY').value = s.posY;
    document.getElementById('sRangeTitle').value = s.titleSize;
    document.getElementById('sRangeQr').value = s.qrSize;
    document.getElementById('sRangeOpacity').value = s.opacity;
    document.getElementById('sRangeGradHeight').value = s.gradHeight;
    updateStoryLabels();
    renderStory(index, 'storyPreviewContainer', false);
}

function updateStorySettings() {
    const s = projectData.storySettings[projectData.storyIndex];
    s.zoom = +document.getElementById('sRangeZoom').value;
    s.posX = +document.getElementById('sRangePosX').value;
    s.posY = +document.getElementById('sRangePosY').value;
    s.titleSize = +document.getElementById('sRangeTitle').value;
    s.qrSize = +document.getElementById('sRangeQr').value;
    s.opacity = +document.getElementById('sRangeOpacity').value;
    s.gradHeight = +document.getElementById('sRangeGradHeight').value;
    updateStoryLabels();
    renderStory(projectData.storyIndex, 'storyPreviewContainer', false);
}

function updateStoryLabels() {
    const s = projectData.storySettings[projectData.storyIndex];
    document.getElementById('sValZoom').innerText = s.zoom;
    document.getElementById('sValPosX').innerText = s.posX;
    document.getElementById('sValPosY').innerText = s.posY;
    document.getElementById('sValTitle').innerText = s.titleSize;
    document.getElementById('sValQr').innerText = s.qrSize;
    document.getElementById('sValOpacity').innerText = s.opacity;
    document.getElementById('sValGradHeight').innerText = s.gradHeight;
}

function toggleStoryDetails() {
    projectData.storySettings[projectData.storyIndex].showDetails = document.getElementById('storyShowDetails').checked;
    renderStory(projectData.storyIndex, 'storyPreviewContainer', false);
}

function resetStoryTransform() {
    const s = projectData.storySettings[projectData.storyIndex];
    s.zoom = 100; s.posX = 0; s.posY = 0;
    loadStorySlideUI(projectData.storyIndex);
}

function renderStory(slideIndex, containerId, returnCanvas = false, callback = null) {
    const canvas = document.createElement('canvas');
    canvas.width = 1080; canvas.height = 1920;
    const ctx = canvas.getContext('2d');
    const s = projectData.storySettings[slideIndex];
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.src = s.imageSrc;
    img.onload = function() {
        const ratio = Math.max(canvas.width / img.width, canvas.height / img.height) * (s.zoom / 100);
        const dw = img.width * ratio;
        const dh = img.height * ratio;
        ctx.drawImage(img, (canvas.width - dw) / 2 + s.posX, (canvas.height - dh) / 2 + s.posY, dw, dh);
        const gradY = 1920 * (1 - s.gradHeight / 100);
        const grad = ctx.createLinearGradient(0, 1920, 0, gradY);
        grad.addColorStop(0, `rgba(0,0,0,${s.opacity / 100})`);
        grad.addColorStop(1, 'transparent');
        ctx.fillStyle = grad;
        ctx.fillRect(0, gradY, 1080, 1920 - gradY);
        ctx.textAlign = 'right';
        ctx.direction = 'rtl';
        document.fonts.ready.then(() => {
            let y = 1750;
            if (s.showDetails) {
                if (s.qrSize > 0) drawQRCode(ctx, projectData.url, 70, y - s.qrSize, s.qrSize);
                y -= (s.qrSize > 0 ? s.qrSize + 40 : 0);
                ctx.font = '500 28px Tahoma';
                ctx.fillStyle = 'rgba(255,255,255,0.9)';
                for (let i = projectData.specs.length - 1; i >= 0; i--) { ctx.fillText(projectData.specs[i], 1010, y); y -= 45; }
                ctx.font = '500 32px Tahoma';
                ctx.fillText(projectData.location, 1010, y);
                y -= 60;
                ctx.font = `900 ${s.titleSize}px Tahoma`;
                ctx.fillStyle = '#fff';
                wrapText(ctx, projectData.title, 1010, y, 940, s.titleSize * 1.3);
            } else if (s.qrSize > 0) {
                drawQRCode(ctx, projectData.url, 70, y - s.qrSize, s.qrSize);
            }
            if (returnCanvas) callback(canvas);
            else { document.getElementById(containerId).innerHTML = ''; document.getElementById(containerId).appendChild(canvas); }
        });
    };
}

function downloadCurrentStory() {
    renderStory(projectData.storyIndex, 'storyPreviewContainer', true, canvas => {
        const a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = `story-${projectData.storyIndex + 1}.png`;
        a.click();
    });
}

function downloadAllStoriesZip() {
    const zip = new JSZip();
    let done = 0;
    projectData.images.forEach((_, idx) => {
        renderStory(idx, 'storyPreviewContainer', true, canvas => {
            canvas.toBlob(blob => {
                zip.file(`story-${idx + 1}.png`, blob);
                done++;
                if (done === projectData.images.length) zip.generateAsync({type: 'blob'}).then(c => saveAs(c, 'stories.zip'));
            });
        });
    });
}

function changePostImage(imgPath, index, element) {
    projectData.postIndex = index;
    projectData.postSettings[index].imageSrc = imgPath;
    loadPostSlideUI(index);
    document.querySelectorAll('#postThumbnailsGrid .thumb-item').forEach(i => i.classList.remove('selected'));
    element.classList.add('selected');
}

function loadPostSlideUI(index) {
    const s = projectData.postSettings[index];
    document.getElementById('postShowDetails').checked = s.showDetails;
    document.getElementById('pRangeZoom').value = s.zoom;
    document.getElementById('pRangePosX').value = s.posX;
    document.getElementById('pRangePosY').value = s.posY;
    document.getElementById('pRangeTitle').value = s.titleSize;
    document.getElementById('pRangeQr').value = s.qrSize;
    document.getElementById('pRangeOpacity').value = s.opacity;
    document.getElementById('pRangeGradHeight').value = s.gradHeight;
    updatePostLabels();
    renderPost(index, 'postPreviewContainer', false);
}

function updatePostSettings() {
    const s = projectData.postSettings[projectData.postIndex];
    s.zoom = +document.getElementById('pRangeZoom').value;
    s.posX = +document.getElementById('pRangePosX').value;
    s.posY = +document.getElementById('pRangePosY').value;
    s.titleSize = +document.getElementById('pRangeTitle').value;
    s.qrSize = +document.getElementById('pRangeQr').value;
    s.opacity = +document.getElementById('pRangeOpacity').value;
    s.gradHeight = +document.getElementById('pRangeGradHeight').value;
    updatePostLabels();
    renderPost(projectData.postIndex, 'postPreviewContainer', false);
}

function updatePostLabels() {
    const s = projectData.postSettings[projectData.postIndex];
    document.getElementById('pValZoom').innerText = s.zoom;
    document.getElementById('pValPosX').innerText = s.posX;
    document.getElementById('pValPosY').innerText = s.posY;
    document.getElementById('pValTitle').innerText = s.titleSize;
    document.getElementById('pValQr').innerText = s.qrSize;
    document.getElementById('pValOpacity').innerText = s.opacity;
    document.getElementById('pValGradHeight').innerText = s.gradHeight;
}

function togglePostDetails() {
    projectData.postSettings[projectData.postIndex].showDetails = document.getElementById('postShowDetails').checked;
    renderPost(projectData.postIndex, 'postPreviewContainer', false);
}

function resetPostTransform() {
    const s = projectData.postSettings[projectData.postIndex];
    s.zoom = 100; s.posX = 0; s.posY = 0;
    loadPostSlideUI(projectData.postIndex);
}

function renderPost(slideIndex, containerId, returnCanvas = false, callback = null) {
    const canvas = document.createElement('canvas');
    canvas.width = 1080; canvas.height = 1350;
    const ctx = canvas.getContext('2d');
    const s = projectData.postSettings[slideIndex];
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.src = s.imageSrc;
    img.onload = function() {
        const ratio = Math.max(canvas.width / img.width, canvas.height / img.height) * (s.zoom / 100);
        const dw = img.width * ratio;
        const dh = img.height * ratio;
        ctx.drawImage(img, (canvas.width - dw) / 2 + s.posX, (canvas.height - dh) / 2 + s.posY, dw, dh);
        const gradY = 1350 * (1 - s.gradHeight / 100);
        const grad = ctx.createLinearGradient(0, 1350, 0, gradY);
        grad.addColorStop(0, `rgba(0,0,0,${s.opacity / 100})`);
        grad.addColorStop(1, 'transparent');
        ctx.fillStyle = grad;
        ctx.fillRect(0, gradY, 1080, 1350 - gradY);
        ctx.textAlign = 'right';
        ctx.direction = 'rtl';
        document.fonts.ready.then(() => {
            let y = 1250;
            if (s.showDetails) {
                if (s.qrSize > 0) drawQRCode(ctx, projectData.url, 70, y - s.qrSize, s.qrSize);
                y -= (s.qrSize > 0 ? s.qrSize + 30 : 0);
                ctx.font = '500 26px Tahoma';
                ctx.fillStyle = 'rgba(255,255,255,0.9)';
                for (let i = projectData.specs.length - 1; i >= 0; i--) { ctx.fillText(projectData.specs[i], 1010, y); y -= 40; }
                ctx.font = '500 30px Tahoma';
                ctx.fillText(projectData.location, 1010, y);
                y -= 55;
                ctx.font = `900 ${s.titleSize}px Tahoma`;
                ctx.fillStyle = '#fff';
                wrapText(ctx, projectData.title, 1010, y, 940, s.titleSize * 1.3);
            } else if (s.qrSize > 0) {
                drawQRCode(ctx, projectData.url, 70, y - s.qrSize, s.qrSize);
            }
            if (returnCanvas) callback(canvas);
            else { document.getElementById(containerId).innerHTML = ''; document.getElementById(containerId).appendChild(canvas); }
        });
    };
}

function downloadCurrentPost() {
    renderPost(projectData.postIndex, 'postPreviewContainer', true, canvas => {
        const a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = `post-${projectData.postIndex + 1}.png`;
        a.click();
    });
}

function downloadAllPostsZip() {
    const zip = new JSZip();
    let done = 0;
    projectData.images.forEach((_, idx) => {
        renderPost(idx, 'postPreviewContainer', true, canvas => {
            canvas.toBlob(blob => {
                zip.file(`post-${idx + 1}.png`, blob);
                done++;
                if (done === projectData.images.length) zip.generateAsync({type: 'blob'}).then(c => saveAs(c, 'posts.zip'));
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if (projectData.images.length > 0) {
        loadStorySlideUI(0);
        loadPostSlideUI(0);
    }
});
</script>