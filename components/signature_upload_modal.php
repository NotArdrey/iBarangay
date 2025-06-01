<?php
global $pdo, $role, $current_admin_id;
?>
<!-- Signature Upload Modal -->
<div id="signatureUploadModal" tabindex="-1"
     class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 overflow-auto">
  <div class="relative w-full max-w-md bg-white rounded-lg shadow">
      <!-- Header -->
      <div class="flex items-start justify-between p-5 border-b rounded-t">
        <h3 class="text-xl font-semibold text-gray-900">Upload E-Signature</h3>
        <button type="button" onclick="toggleSignatureModal()"
                class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center">
          <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
          </svg>
        </button>
      </div>
      
      <!-- Current Signatures Display -->
      <?php
      // Get current user's signatures
      $currentSignatures = [];
      if (in_array($role, [ROLE_CAPTAIN])) {
          $stmt = $pdo->prepare("SELECT esignature_path FROM users WHERE id = ? AND esignature_path IS NOT NULL");
          $stmt->execute([$current_admin_id]);
          $currentSignatures['captain'] = $stmt->fetchColumn();
      }
      if (in_array($role, [ROLE_CHIEF])) {
          $stmt = $pdo->prepare("SELECT chief_officer_esignature_path FROM users WHERE id = ? AND chief_officer_esignature_path IS NOT NULL");
          $stmt->execute([$current_admin_id]);
          $currentSignatures['chief'] = $stmt->fetchColumn();
      }
      ?>

      <?php if (!empty($currentSignatures)): ?>
      <div class="p-4 bg-gray-50 border-b">
        <h4 class="text-sm font-medium text-gray-700 mb-3">Current Signatures</h4>
        <?php foreach ($currentSignatures as $type => $path): ?>
          <?php 
            // build server‐side file path
            $serverFile = $_SERVER['DOCUMENT_ROOT'] . '/iBarangay/' . $path;
          ?>
          <?php if ($path && file_exists($serverFile)): ?>
          <div class="mb-3">
            <label class="block text-xs text-gray-600 mb-1">
              <?= $type==='captain' ? 'Captain E-Signature' : 'Chief Officer E-Signature' ?>
            </label>
            <img 
              src="/iBarangay/<?= htmlspecialchars($path) ?>" 
              alt="Current signature" 
              class="max-w-full h-auto border rounded" 
              style="max-height:80px;"
            >
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      
      <!-- Form -->
      <form id="signatureUploadForm"
            method="POST"
            action="../pages/blotter.php?action=upload_signature"
            enctype="multipart/form-data" class="p-6 space-y-4">
        
        <!-- Use specific signature type field instead of role -->
        <input type="hidden" name="signature_type" value="<?= in_array($role, [ROLE_CAPTAIN]) ? 'captain' : 'chief' ?>">
        
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            <?= in_array($role, [ROLE_CAPTAIN]) ? 'Captain E-Signature' : 'Chief Officer E-Signature' ?>
          </label>
          <input type="file" name="signature_file" accept="image/*" required
                 class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
          <p class="text-xs text-gray-500 mt-1">Supported formats: JPG, PNG, GIF. Max size: 2MB</p>
        </div>
        
        <div class="preview-container hidden">
          <label class="block text-sm font-medium text-gray-700 mb-2">Preview</label>
          <img id="signaturePreview" class="max-w-full h-auto border rounded" style="max-height: 150px;">
        </div>
        
        <!-- Footer -->
        <div class="flex items-center justify-end pt-4 space-x-3 border-t border-gray-200">
          <button type="button" onclick="toggleSignatureModal()"
                  class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900">
            Cancel
          </button>
          <button type="submit" name="upload_signature"
                  class="text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center">
            Upload Signature
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleSignatureModal() {
    const modal = document.getElementById('signatureUploadModal');
    if (modal) {
        modal.classList.toggle('hidden');
        if (!modal.classList.contains('hidden')) {
            // Reset form when opening
            const form = modal.querySelector('form');
            if (form) form.reset();
            const previewContainer = document.querySelector('.preview-container');
            if (previewContainer) previewContainer.classList.add('hidden');
        }
    }
}

// Preview uploaded signature
document.addEventListener('change', function(e) {
    if (e.target.name === 'signature_file') {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('signaturePreview');
                const container = document.querySelector('.preview-container');
                if (preview && container) {
                    preview.src = e.target.result;
                    container.classList.remove('hidden');
                }
            };
            reader.readAsDataURL(file);
        }
    }
});
</script>
