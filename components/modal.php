<?php
/**
 * Expects:
 *   $modalId       = string, unique DOM id
 *   $modalTitle    = string
 *   $formFields    = callback that echoes the inner <form> fields
 *   $submitLabel   = string
 */
?>
<div id="<?= $modalId ?>" tabindex="-1" class="hidden fixed inset-0 z-50 p-4 overflow-y-auto">
  <div class="relative w-full max-w-2xl mx-auto">
    <div class="bg-white rounded-lg shadow">
      <div class="flex items-start justify-between p-5 border-b">
        <h3 class="text-xl font-semibold"><?= htmlspecialchars($modalTitle) ?></h3>
        <button onclick="toggleModal('<?= $modalId ?>')" class="text-gray-400 hover:bg-gray-200 rounded-lg p-1">
          <svg class="w-4 h-4" …>…</svg>
        </button>
      </div>
      <form method="POST" class="p-6 space-y-4">
        <?php $formFields(); ?>
        <div class="flex justify-end space-x-3 border-t pt-4">
          <button type="submit"
                  class="px-5 py-2.5 text-white bg-blue-600 hover:bg-blue-700 rounded-lg">
            <?= htmlspecialchars($submitLabel) ?>
          </button>
          <button type="button"
                  onclick="toggleModal('<?= $modalId ?>')"
                  class="px-5 py-2.5 border rounded-lg hover:bg-gray-100">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
