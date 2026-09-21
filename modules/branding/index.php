<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$user=currentUser(); $perms=is_string($user['permissions'])?json_decode($user['permissions'],true):($user['permissions']??[]);
if(empty($perms['all'])){$_SESSION['flash_error']='Admin access required.';header('Location: '.APP_URL.'/modules/dashboard/index.php');exit;}
$db=getDB(); $currentModule='branding'; $pageTitle='Branding & Theme';
$uploadDir=__DIR__.'/../../uploads/logos/';
$uploadDirUrl='uploads/logos/';
if(!is_dir($uploadDir)) mkdir($uploadDir,0755,true);

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_FILES['logo'])){
    $file=$_FILES['logo']; $allowed=['image/png','image/jpeg','image/jpg','image/svg+xml','image/gif','image/webp'];
    if($file['error']!==UPLOAD_ERR_OK) $_SESSION['flash_error']='Upload failed. Please try again.';
    elseif(!in_array($file['type'],$allowed)) $_SESSION['flash_error']='Invalid file type. Use PNG, JPG, SVG, or WebP.';
    elseif($file['size']>2*1024*1024) $_SESSION['flash_error']='File too large. Max 2MB.';
    else{
        $old=getSetting('logo_path','');
        if($old&&file_exists(__DIR__.'/../../'.$old)) @unlink(__DIR__.'/../../'.$old);
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        $filename='logo_'.time().'.'.$ext;
        if(move_uploaded_file($file['tmp_name'],$uploadDir.$filename)){saveSetting('logo_path',$uploadDirUrl.$filename);$_SESSION['flash_success']='Logo uploaded successfully!';}
        else $_SESSION['flash_error']='Failed to save file. Check folder permissions on uploads/logos/';
    }
    header('Location: '.APP_URL.'/modules/branding/index.php'); exit;
}
if(get('action')==='remove_logo'){$old=getSetting('logo_path','');if($old&&file_exists(__DIR__.'/../../'.$old))@unlink(__DIR__.'/../../'.$old);saveSetting('logo_path','');$_SESSION['flash_success']='Logo removed.';header('Location: '.APP_URL.'/modules/branding/index.php');exit;}
if($_SERVER['REQUEST_METHOD']==='POST'&&post('form')==='theme'){saveSetting('brand_color',post('brand_color','blue'));saveSetting('app_name',post('app_name','ZohoBooks'));$_SESSION['flash_success']='Branding saved! Page will reload to apply changes.';header('Location: '.APP_URL.'/modules/branding/index.php');exit;}

$currentTheme=getSetting('brand_color','blue');
$currentLogo=getSetting('logo_path','');
$currentAppName=getSetting('app_name',APP_NAME);
$themes=['blue'=>['name'=>'Ocean Blue','primary'=>'#2563eb','sidebar'=>'#0f172a','accent'=>'#3b82f6'],'green'=>['name'=>'Forest Green','primary'=>'#16a34a','sidebar'=>'#052e16','accent'=>'#22c55e'],'purple'=>['name'=>'Royal Purple','primary'=>'#7c3aed','sidebar'=>'#2e1065','accent'=>'#8b5cf6'],'red'=>['name'=>'Crimson Red','primary'=>'#dc2626','sidebar'=>'#1c0606','accent'=>'#ef4444'],'orange'=>['name'=>'Sunset Orange','primary'=>'#ea580c','sidebar'=>'#431407','accent'=>'#f97316'],'teal'=>['name'=>'Teal','primary'=>'#0d9488','sidebar'=>'#042f2e','accent'=>'#14b8a6'],'rose'=>['name'=>'Rose','primary'=>'#e11d48','sidebar'=>'#1c0010','accent'=>'#f43f5e'],'slate'=>['name'=>'Slate Gray','primary'=>'#475569','sidebar'=>'#020617','accent'=>'#64748b'],'olive'=>['name'=>'Olive & Cream','primary'=>'#4C5135','sidebar'=>'#2b2e1c','accent'=>'#6b7048']];
include __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-4xl mx-auto space-y-6">

  <!-- Logo Upload -->
  <div class="card p-6">
    <div class="flex items-center gap-3 mb-5">
      <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:var(--brand-light)"><i data-lucide="image" class="w-5 h-5" style="color:var(--brand)"></i></div>
      <div><h2 class="text-sm font-semibold text-gray-800">Company Logo</h2><p class="text-xs text-gray-400">Shown in the sidebar. PNG, JPG, SVG, or WebP — max 2MB.</p></div>
    </div>
    <div class="flex items-start gap-8">
      <div class="flex-shrink-0">
        <p class="text-xs font-medium text-gray-500 mb-2">Current Logo</p>
        <div class="w-32 h-32 rounded-2xl border-2 border-dashed border-gray-200 flex items-center justify-center bg-gray-50 overflow-hidden">
          <?php $logoFullPath=$currentLogo?__DIR__.'/../../'.$currentLogo:''; ?>
          <?php if($currentLogo&&file_exists($logoFullPath)): ?>
          <img src="<?=APP_URL?>/<?=clean($currentLogo)?>?v=<?=filemtime($logoFullPath)?>" alt="Logo" class="max-w-full max-h-full object-contain p-2">
          <?php else: ?><div class="text-center text-gray-300"><i data-lucide="image-off" class="w-8 h-8 mx-auto mb-1"></i><p class="text-xs">No logo</p></div><?php endif; ?>
        </div>
        <?php if($currentLogo&&file_exists($logoFullPath)): ?>
        <a href="?action=remove_logo" onclick="return confirm('Remove logo?')" class="mt-2 flex items-center justify-center gap-1 text-xs text-red-500 hover:text-red-700"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i>Remove</a>
        <?php endif; ?>
      </div>
      <div class="flex-1">
        <form method="POST" enctype="multipart/form-data" id="logoForm">
          <div id="dropZone" class="border-2 border-dashed border-gray-200 rounded-2xl p-8 text-center cursor-pointer hover:border-gray-400 transition-colors" onclick="document.getElementById('logoFile').click()" ondragover="event.preventDefault();this.classList.add('border-blue-400','bg-blue-50')" ondragleave="this.classList.remove('border-blue-400','bg-blue-50')" ondrop="handleDrop(event)">
            <i data-lucide="upload-cloud" class="w-10 h-10 mx-auto mb-3 text-gray-300"></i>
            <p class="text-sm font-medium text-gray-600">Drop your logo here</p>
            <p class="text-xs text-gray-400 mt-1">or click to browse</p>
            <p class="text-xs text-gray-300 mt-2">PNG, JPG, SVG, WebP — max 2MB</p>
          </div>
          <input type="file" name="logo" id="logoFile" accept="image/*" class="hidden" onchange="previewLogo(this)">
          <div id="previewArea" class="hidden mt-4">
            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
              <img id="previewImg" src="" alt="" class="w-12 h-12 object-contain rounded-lg border border-gray-200 bg-white">
              <div class="flex-1 min-w-0"><p id="previewName" class="text-xs font-medium text-gray-700 truncate"></p><p id="previewSize" class="text-xs text-gray-400"></p></div>
              <button type="button" onclick="clearPreview()" class="text-gray-400 hover:text-red-500"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <button type="submit" class="btn-primary w-full justify-center mt-3"><i data-lucide="upload" class="w-4 h-4"></i> Upload Logo</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Theme -->
  <div class="card p-6">
    <form method="POST">
      <input type="hidden" name="form" value="theme">
      <div class="flex items-center gap-3 mb-5">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:var(--brand-light)"><i data-lucide="palette" class="w-5 h-5" style="color:var(--brand)"></i></div>
        <div><h2 class="text-sm font-semibold text-gray-800">App Name & Color Theme</h2><p class="text-xs text-gray-400">Customize your app name and pick a brand color.</p></div>
      </div>
      <div class="mb-6"><label class="form-label">App / Company Name</label><input type="text" name="app_name" class="form-input max-w-sm" value="<?=clean($currentAppName)?>" placeholder="Your Company Name"></div>
      <label class="form-label mb-3 block">Color Theme</label>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <?php foreach($themes as $key=>$t): ?>
        <label class="cursor-pointer group">
          <input type="radio" name="brand_color" value="<?=$key?>" <?=$currentTheme===$key?'checked':''?> class="sr-only peer">
          <div class="relative rounded-2xl overflow-hidden border-2 transition-all peer-checked:border-gray-800 border-transparent peer-checked:shadow-lg group-hover:border-gray-300">
            <div class="h-16 flex">
              <div class="w-10 flex-shrink-0 flex flex-col gap-1.5 p-1.5" style="background:<?=$t['sidebar']?>">
                <div class="w-full h-1.5 rounded-full opacity-60" style="background:<?=$t['accent']?>"></div>
                <div class="w-3/4 h-1 rounded-full opacity-30 bg-white"></div>
                <div class="w-3/4 h-1 rounded-full opacity-30 bg-white"></div>
                <div class="w-3/4 h-1 rounded-full opacity-30 bg-white"></div>
              </div>
              <div class="flex-1 p-2" style="background:#f8fafc">
                <div class="w-full h-2 rounded mb-1.5" style="background:<?=$t['primary']?>;opacity:0.8"></div>
                <div class="w-2/3 h-1.5 rounded bg-gray-200"></div>
                <div class="w-full h-4 rounded mt-1.5 bg-white border border-gray-100"></div>
              </div>
            </div>
            <div class="px-2 py-1.5 flex items-center gap-1.5 bg-white border-t border-gray-100">
              <div class="w-3 h-3 rounded-full flex-shrink-0" style="background:<?=$t['primary']?>"></div>
              <span class="text-xs font-medium text-gray-700 truncate"><?=$t['name']?></span>
            </div>
            <?php if($currentTheme===$key): ?><div class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full flex items-center justify-center" style="background:<?=$t['primary']?>"><i data-lucide="check" class="w-3 h-3 text-white"></i></div><?php endif; ?>
          </div>
        </label>
        <?php endforeach; ?>
      </div>

      <!-- Live Preview -->
      <div class="rounded-xl overflow-hidden border border-gray-200 mb-6">
        <div class="px-4 py-2 text-xs font-medium text-white flex items-center gap-2" id="previewBar" style="background:var(--brand)"><i data-lucide="eye" class="w-3.5 h-3.5"></i>Live preview — colors will look like this</div>
        <div class="p-4 flex items-center gap-3 bg-gray-50">
          <button type="button" id="previewBtn" class="btn-primary text-xs" style="background:var(--brand)">Primary Button</button>
          <span class="text-xs font-medium" style="color:var(--brand)">Link text color</span>
          <div class="w-4 h-4 rounded-full" style="background:var(--brand)"></div>
        </div>
      </div>
      <div class="flex justify-end"><button type="submit" class="btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Branding</button></div>
    </form>
  </div>

  <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 text-sm text-blue-700">
    <strong>Tip:</strong> After saving, the page refreshes automatically and all colors — sidebar, buttons, links, and accents — update across the entire app.
  </div>
</div>

<script>
function handleDrop(e){e.preventDefault();document.getElementById('dropZone').classList.remove('border-blue-400','bg-blue-50');const file=e.dataTransfer.files[0];if(file&&file.type.startsWith('image/')){const dt=new DataTransfer();dt.items.add(file);document.getElementById('logoFile').files=dt.files;previewLogo(document.getElementById('logoFile'));}}
function previewLogo(input){const file=input.files[0];if(!file)return;const reader=new FileReader();reader.onload=e=>{document.getElementById('previewImg').src=e.target.result;document.getElementById('previewName').textContent=file.name;document.getElementById('previewSize').textContent=(file.size/1024).toFixed(1)+' KB';document.getElementById('previewArea').classList.remove('hidden');document.getElementById('dropZone').classList.add('hidden');};reader.readAsDataURL(file);}
function clearPreview(){document.getElementById('logoFile').value='';document.getElementById('previewArea').classList.add('hidden');document.getElementById('dropZone').classList.remove('hidden');}
const themeColors=<?=json_encode(array_column($themes,'primary',null))?>;
const themeKeys=<?=json_encode(array_keys($themes))?>;
const colorMap={};themeKeys.forEach((k,i)=>colorMap[k]=Object.values(themeColors)[i]);
document.querySelectorAll('[name="brand_color"]').forEach(radio=>{radio.addEventListener('change',function(){const color=colorMap[this.value]||'#2563eb';document.getElementById('previewBar').style.background=color;document.getElementById('previewBtn').style.background=color;});});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
