<?php
/**
 * Expects:
 *   $columns = [ 'Header 1', 'Header 2', … ];
 *   $rows    = [
 *     [ 'col1val', 'col2val', … ],
 *     …
 *   ];
 *   $actionsRenderer = function($row){ … return HTML for the last cell … };
 */
?>
<div class="bg-white rounded-lg shadow overflow-hidden border border-gray-200">
  <div class="overflow-x-auto">
    <table class="w-full text-sm text-left text-gray-500">
      <thead class="text-xs text-gray-700 uppercase bg-gray-50">
        <tr>
          <?php foreach ($columns as $col): ?>
            <th class="px-4 py-3"><?= htmlspecialchars($col) ?></th>
          <?php endforeach ?>
          <?php if (isset($actionsRenderer)): ?>
            <th class="px-4 py-3 text-right">Actions</th>
          <?php endif ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
        <?php if (count($rows)): ?>
          <?php foreach ($rows as $row): ?>
            <tr class="hover:bg-gray-50">
              <?php foreach ($row as $cell): ?>
                <td class="px-4 py-4 whitespace-nowrap"><?= htmlspecialchars($cell) ?></td>
              <?php endforeach ?>
              <?php if (isset($actionsRenderer)): ?>
                <td class="px-4 py-4 text-right"><?= $actionsRenderer($row) ?></td>
              <?php endif ?>
            </tr>
          <?php endforeach ?>
        <?php else: ?>
          <tr>
            <td colspan="<?= count($columns) + (isset($actionsRenderer)?1:0) ?>" class="px-4 py-4 text-center text-gray-500">
              No records found
            </td>
          </tr>
        <?php endif ?>
      </tbody>
    </table>
  </div>
</div>
