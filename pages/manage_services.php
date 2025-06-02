<?php
session_start();
require_once '../config/dbconn.php';
require_once "../components/header.php";

// Check if user has appropriate role
if (!in_array($_SESSION['role_id'], [3,4,5,6,7])) {
    header("Location: ../index.php");
    exit();
}

$barangay_id = $_SESSION['barangay_id'];

// Fetch services
$stmt = $pdo->prepare("
    SELECT * FROM custom_services 
    WHERE barangay_id = ?
    ORDER BY display_order, name
");
$stmt->execute([$barangay_id]);
$services = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Services - iBarangay</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-blue-800">Manage Services</h1>
            <div class="space-x-4">
                <button onclick="showAddServiceModal()" class="w-full px-4 py-3 bg-blue-600 text-white rounded-md text-lg font-semibold hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <i class="fas fa-plus-circle mr-2"></i>Add Service
                </button>
            </div>
        </div>

        <!-- Services Section -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4">Available Services</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($services as $service): ?>
                <div class="border rounded-lg p-4 <?php echo $service['is_active'] ? 'bg-white' : 'bg-gray-100'; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex items-center">
                            <i class="fas <?php echo htmlspecialchars($service['icon']); ?> text-2xl text-green-500 mr-3"></i>
                            <div>
                                <h3 class="text-lg font-medium"><?php echo htmlspecialchars($service['name']); ?></h3>
                                <?php if (!empty($service['service_photo'])): ?>
                                    <span class="text-xs text-green-600"><i class="fas fa-image mr-1"></i>Photo attached</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                       
                            <button onclick="viewService(<?php echo $service['id']; ?>)" class="text-blue-500">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button onclick="editService(<?php echo $service['id']; ?>)" class="text-yellow-500">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteService(<?php echo $service['id']; ?>)" class="text-red-500">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <p class="text-gray-600 text-sm mb-3"><?php echo htmlspecialchars($service['description']); ?></p>
                    <div class="text-sm text-gray-500">
                        <p><i class="fas fa-clock mr-2"></i><?php echo htmlspecialchars($service['processing_time']); ?></p>
                        <p><i class="fas fa-money-bill mr-2"></i><?php echo htmlspecialchars($service['fees']); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div id="addServiceModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-8 max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-3xl font-bold text-blue-800">Add Service</h2>
                <button onclick="closeAddServiceModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="addServiceForm" onsubmit="submitService(event)" enctype="multipart/form-data">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Left Column -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceType">
                                Service Type
                            </label>
                            <select id="serviceType" name="service_type" required
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                <option value="general">General Service</option>
                                <option value="health">Health Service</option>
                                <option value="education">Educational Service</option>
                                <option value="social">Social Service</option>
                                <option value="community">Community Service</option>
                                <option value="business">Business-related Service</option>
                                <option value="environmental">Environmental Service</option>
                                <option value="emergency">Emergency Service</option>
                                <option value="legal">Legal Service</option>
                                <option value="financial">Financial Service</option>
                                <option value="technical">Technical Service</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceName">
                                Service Name
                            </label>
                            <input type="text" id="serviceName" name="name" required
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceDescription">
                                Description
                            </label>
                            <textarea id="serviceDescription" name="description" required
                                      class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                      rows="3"></textarea>
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceIcon">
                                Icon
                            </label>
                            <select id="serviceIcon" name="icon" required
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                <option value="fa-cog">⚙️ General Service</option>
                                <option value="fa-heart">❤️ Health</option>
                                <option value="fa-graduation-cap">🎓 Education</option>
                                <option value="fa-hands-helping">🤝 Social Services</option>
                                <option value="fa-users">👥 Community</option>
                                <option value="fa-home">🏠 Housing</option>
                                <option value="fa-briefcase">💼 Employment</option>
                                <option value="fa-seedling">🌱 Environment</option>
                                <option value="fa-basketball-ball">⚽ Sports</option>
                                <option value="fa-palette">🎨 Arts & Culture</option>
                                <option value="fa-tools">🔧 Technical</option>
                                <option value="fa-hand-holding-usd">💰 Financial</option>
                                <option value="fa-gavel">⚖️ Legal</option>
                                <option value="fa-ambulance">🚑 Emergency</option>
                                <option value="fa-building">🏢 Business</option>
                                <option value="fa-file-alt">📄 Documentation</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="servicePhoto">
                                Service Photo <span class="text-red-500">*</span>
                            </label>
                            <div class="border-2 border-dashed border-gray-300 rounded-lg p-4">
                                <input type="file" id="servicePhoto" name="service_photo" accept="image/*" required
                                       class="hidden" onchange="handlePhotoUpload(this)">
                                <div id="photoUploadArea" class="text-center cursor-pointer" onclick="document.getElementById('servicePhoto').click()">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                                    <p class="text-gray-500">Click to upload service photo</p>
                                    <p class="text-xs text-gray-400">PNG, JPG, JPEG up to 5MB</p>
                                </div>
                                <div id="photoPreview" class="hidden mt-4">
                                    <img id="previewImage" src="" alt="Preview" class="w-full h-32 object-cover rounded">
                                    <button type="button" onclick="removePhoto()" class="mt-2 text-red-500 text-sm">
                                        <i class="fas fa-times mr-1"></i>Remove Photo
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceRequirements">
                                Requirements (One per line)
                            </label>
                            <textarea id="serviceRequirements" name="requirements" required
                                      class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                      rows="4" placeholder="- Valid ID&#10;- Proof of residency&#10;- Application form"></textarea>
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceGuide">
                                Step-by-Step Guide (One step per line)
                            </label>
                            <textarea id="serviceGuide" name="detailed_guide" required
                                      class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                      rows="4" placeholder="1. Submit requirements&#10;2. Pay the fee&#10;3. Wait for processing"></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceProcessingTime">
                                    Processing Time
                                </label>
                                <input type="text" id="serviceProcessingTime" name="processing_time" required
                                       placeholder="e.g., 3-5 working days"
                                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                            </div>
                            <div>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceFees">
                                    Fees
                                </label>
                                <input type="text" id="serviceFees" name="fees" required
                                       placeholder="e.g., ₱100.00 or Free"
                                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                            </div>
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="servicePriority">
                                Priority Level
                            </label>
                            <select id="servicePriority" name="priority" required
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                <option value="normal">Normal</option>
                                <option value="high">High Priority</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="serviceAvailability">
                                Service Availability
                            </label>
                            <select id="serviceAvailability" name="availability" required
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                <option value="always">Always Available</option>
                                <option value="scheduled">Scheduled Only</option>
                                <option value="limited">Limited Availability</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="additionalNotes">
                                Additional Notes (Optional)
                            </label>
                            <textarea id="additionalNotes" name="additional_notes"
                                      class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                      rows="3" placeholder="Any additional information about the service"></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end mt-6">
                    <button type="submit" class="w-full px-4 py-3 bg-blue-500 text-white rounded-md text-lg font-semibold hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Add Service
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Service Modal -->
    <div id="viewServiceModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-8 max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-2xl font-bold" id="modalServiceTitle"></h2>
                <button onclick="closeViewServiceModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Left Column - Service Details -->
                <div class="space-y-6">
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Description</h3>
                        <p id="modalServiceDescription" class="text-gray-600"></p>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Requirements</h3>
                        <div id="modalServiceRequirements" class="text-gray-600"></div>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold mb-2">Step-by-Step Guide</h3>
                        <div id="modalServiceGuide" class="text-gray-600"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <h3 class="text-lg font-semibold mb-2">Processing Time</h3>
                            <p id="modalServiceProcessingTime" class="text-gray-600"></p>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold mb-2">Fees</h3>
                            <p id="modalServiceFees" class="text-gray-600"></p>
                        </div>
                    </div>
                    <div id="modalAdditionalNotes" class="hidden">
                        <h3 class="text-lg font-semibold mb-2">Additional Notes</h3>
                        <p id="modalServiceNotes" class="text-gray-600"></p>
                    </div>
                </div>
                
                <!-- Right Column - Service Photo -->
                <div>
                    <h3 class="text-lg font-semibold mb-2">Service Photo</h3>
                    <div id="modalServicePhotoContainer" class="border rounded-lg p-4">
                        <img id="modalServicePhoto" src="" alt="Service Photo" class="w-full h-64 object-cover rounded-lg hidden">
                        <div id="modalNoPhoto" class="text-center py-16">
                            <i class="fas fa-image text-4xl text-gray-300 mb-2"></i>
                            <p class="text-gray-500">No photo available</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Service Modal -->
    <div id="editServiceModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-8 max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-2xl font-bold">Edit Service</h2>
                <button onclick="closeEditServiceModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editServiceForm" onsubmit="submitEditService(event)" enctype="multipart/form-data">
                <input type="hidden" id="editServiceId" name="service_id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Left Column -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="editServiceName">
                                Service Name
                            </label>
                            <input type="text" id="editServiceName" name="name" required
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="editServiceDescription">
                                Description
                            </label>
                            <textarea id="editServiceDescription" name="description" required
                                      class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"></textarea>
                        </div>
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="editServiceIcon">
                                Icon
                            </label>
                            <select id="editServiceIcon" name="icon" required
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                <option value="fa-cog">⚙️ General Service</option>
                                <option value="fa-heart">❤️ Health</option>
                                <option value="fa-graduation-cap">🎓 Education</option>
                                <option value="fa-hands-helping">🤝 Social Services</option>
                                <option value="fa-users">👥 community</option>
                                <option value="fa-home">🏠 Housing</option>
                                <option value="fa-briefcase">💼 Employment</option>
                                <option value="fa-seedling">🌱 Environment</option>
                                <option value="fa-basketball-ball">⚽ Sports</option>
                                <option value="fa-palette">🎨 Arts & Culture</option>
                                <option value="fa-tools">🔧 Technical</option>
                                <option value="fa-hand-holding-usd">💰 Financial</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="editServicePhoto">
                                Update Service Photo (Optional)
                            </label>
                            <div class="border-2 border-dashed border-gray-300 rounded-lg p-4">
                                <input type="file" id="editServicePhoto" name="service_photo" accept="image/*"
                                       class="hidden" onchange="handleEditPhotoUpload(this)">
                                <div id="editPhotoUploadArea" class="text-center cursor-pointer" onclick="document.getElementById('editServicePhoto').click()">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                                    <p class="text-gray-500">Click to update service photo</p>
                                    <p class="text-xs text-gray-400">PNG, JPG, JPEG up to 5MB</p>
                                </div>
                                <div id="editPhotoPreview" class="hidden mt-4">
                                    <img id="editPreviewImage" src="" alt="Preview" class="w-full h-32 object-cover rounded">
                                    <button type="button" onclick="removeEditPhoto()" class="mt-2 text-red-500 text-sm">
                                        <i class="fas fa-times mr-1"></i>Remove Photo
                                    </button>
                                </div>
                                <div id="editCurrentPhoto" class="hidden mt-4">
                                    <p class="text-sm text-gray-600 mb-2">Current Photo:</p>
                                    <img id="editCurrentPhotoImg" src="" alt="Current Photo" class="w-full h-32 object-cover rounded">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="editServiceRequirements">
                                Requirements (One per line)
                            </label>
                            <textarea id="editServiceRequirements" name="requirements" required
                                      class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                      rows="4"></textarea>
                        </div>
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="editServiceGuide">
                                Step-by-Step Guide (One step per line)
                            </label>
                            <textarea id="editServiceGuide" name="detailed_guide" required
                                      class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                      rows="4"></textarea>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="editServiceProcessingTime">
                                    Processing Time
                                </label>
                                <input type="text" id="editServiceProcessingTime" name="processing_time" required
                                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                            </div>
                            <div>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="editServiceFees">
                                    Fees
                                </label>
                                <input type="text" id="editServiceFees" name="fees" required
                                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end mt-6">
                    <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                        Update Service
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Photo Upload Functions
    function handlePhotoUpload(input) {
        const file = input.files[0];
        if (file) {
            // Validate file size (5MB limit)
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire({
                    title: 'Error!',
                    text: 'File size must be less than 5MB',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                input.value = '';
                return;
            }
            
            // Validate file type
            if (!file.type.startsWith('image/')) {
                Swal.fire({
                    title: 'Error!',
                    text: 'Please select a valid image file',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                input.value = '';
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewImage').src = e.target.result;
                document.getElementById('photoUploadArea').style.display = 'none';
                document.getElementById('photoPreview').classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }
    }

    function removePhoto() {
        document.getElementById('servicePhoto').value = '';
        document.getElementById('photoUploadArea').style.display = 'block';
        document.getElementById('photoPreview').classList.add('hidden');
        document.getElementById('previewImage').src = '';
    }

    function handleEditPhotoUpload(input) {
        const file = input.files[0];
        if (file) {
            // Validate file size (5MB limit)
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire({
                    title: 'Error!',
                    text: 'File size must be less than 5MB',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                input.value = '';
                return;
            }
            
            // Validate file type
            if (!file.type.startsWith('image/')) {
                Swal.fire({
                    title: 'Error!',
                    text: 'Please select a valid image file',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                input.value = '';
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('editPreviewImage').src = e.target.result;
                document.getElementById('editPhotoUploadArea').style.display = 'none';
                document.getElementById('editCurrentPhoto').classList.add('hidden');
                document.getElementById('editPhotoPreview').classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }
    }

    function removeEditPhoto() {
        document.getElementById('editServicePhoto').value = '';
        document.getElementById('editPhotoUploadArea').style.display = 'block';
        document.getElementById('editPhotoPreview').classList.add('hidden');
        document.getElementById('editCurrentPhoto').classList.remove('hidden');
        document.getElementById('editPreviewImage').src = '';
    }

    // Modal Functions
    function showAddServiceModal() {
        document.getElementById('addServiceModal').style.display = 'flex';
    }

    function closeAddServiceModal() {
        document.getElementById('addServiceModal').style.display = 'none';
        document.getElementById('addServiceForm').reset();
        removePhoto();
    }

    function closeViewServiceModal() {
        document.getElementById('viewServiceModal').style.display = 'none';
    }

    function closeEditServiceModal() {
        document.getElementById('editServiceModal').style.display = 'none';
        removeEditPhoto();
    }

    // Form Submissions
    function submitService(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        
        // Add additional fields
        formData.append('service_type', document.getElementById('serviceType').value);
        formData.append('priority', document.getElementById('servicePriority').value);
        formData.append('availability', document.getElementById('serviceAvailability').value);
        formData.append('additional_notes', document.getElementById('additionalNotes').value);

        fetch('../functions/add_service.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: 'Success!',
                    text: 'Service added successfully',
                    icon: 'success',
                    confirmButtonText: 'OK'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    title: 'Error!',
                    text: data.message || 'Failed to add service',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error!',
                text: 'An unexpected error occurred',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        });
    }

    // Auto-update icon based on service type
    document.getElementById('serviceType').addEventListener('change', function(e) {
        const iconSelect = document.getElementById('serviceIcon');
        switch(e.target.value) {
            case 'health':
                iconSelect.value = 'fa-heart';
                break;
            case 'education':
                iconSelect.value = 'fa-graduation-cap';
                break;
            case 'social':
                iconSelect.value = 'fa-hands-helping';
                break;
            case 'community':
                iconSelect.value = 'fa-users';
                break;
            case 'business':
                iconSelect.value = 'fa-building';
                break;
            case 'environmental':
                iconSelect.value = 'fa-seedling';
                break;
            case 'emergency':
                iconSelect.value = 'fa-ambulance';
                break;
            case 'legal':
                iconSelect.value = 'fa-gavel';
                break;
            case 'financial':
                iconSelect.value = 'fa-hand-holding-usd';
                break;
            case 'technical':
                iconSelect.value = 'fa-tools';
                break;
            default:
                iconSelect.value = 'fa-cog';
        }
    });

    // Toggle Status Functions
    function toggleServiceStatus(serviceId) {
        fetch('../functions/toggle_service_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ service_id: serviceId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                Swal.fire({
                    title: 'Error!',
                    text: data.message || 'Failed to toggle service status',
                    icon: 'error',
                    confirmButtonColor: '#d33'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error!',
                text: 'An unexpected error occurred',
                icon: 'error',
                confirmButtonColor: '#d33'
            });
        });
    }

    // View Service Details
    function viewService(serviceId) {
        fetch(`../functions/get_service_details.php?id=${serviceId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const service = data.data;
                    document.getElementById('modalServiceTitle').textContent = service.name;
                    document.getElementById('modalServiceDescription').textContent = service.description;
                    document.getElementById('modalServiceRequirements').innerHTML = formatTextAsList(service.requirements);
                    document.getElementById('modalServiceGuide').innerHTML = formatTextAsList(service.detailed_guide);
                    document.getElementById('modalServiceProcessingTime').textContent = service.processing_time || 'Not specified';
                    document.getElementById('modalServiceFees').textContent = service.fees || 'Not specified';
                    
                    // Handle additional notes
                    if (service.additional_notes && service.additional_notes.trim()) {
                        document.getElementById('modalServiceNotes').textContent = service.additional_notes;
                        document.getElementById('modalAdditionalNotes').classList.remove('hidden');
                    } else {
                        document.getElementById('modalAdditionalNotes').classList.add('hidden');
                    }
                    
                    // Handle service photo
                    if (service.service_photo) {
                        document.getElementById('modalServicePhoto').src = '../uploads/service_photos/' + service.service_photo;
                        document.getElementById('modalServicePhoto').classList.remove('hidden');
                        document.getElementById('modalNoPhoto').style.display = 'none';
                    } else {
                        document.getElementById('modalServicePhoto').classList.add('hidden');
                        document.getElementById('modalNoPhoto').style.display = 'block';
                    }
                    
                    document.getElementById('viewServiceModal').style.display = 'flex';
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: data.message || 'Failed to load service details',
                        icon: 'error',
                        confirmButtonColor: '#d33'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    title: 'Error!',
                    text: 'Failed to load service details',
                    icon: 'error',
                    confirmButtonColor: '#d33'
                });
            });
    }

    function formatTextAsList(text) {
        if (!text) return '';
        return text.split('\n')
            .map(line => line.trim())
            .filter(line => line)
            .map((line, index) => `<p class="mb-2">${index + 1}. ${line}</p>`)
            .join('');
    }

    // Edit Function
    function editService(serviceId) {
        fetch(`../functions/get_service_details.php?id=${serviceId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const service = data.data;
                    document.getElementById('editServiceId').value = service.id;
                    document.getElementById('editServiceName').value = service.name;
                    document.getElementById('editServiceDescription').value = service.description;
                    document.getElementById('editServiceIcon').value = service.icon;
                    document.getElementById('editServiceRequirements').value = service.requirements;
                    document.getElementById('editServiceGuide').value = service.detailed_guide;
                    document.getElementById('editServiceProcessingTime').value = service.processing_time;
                    document.getElementById('editServiceFees').value = service.fees;
                    
                    // Handle existing photo
                    if (service.service_photo) {
                        document.getElementById('editCurrentPhotoImg').src = '../uploads/service_photos/' + service.service_photo;
                        document.getElementById('editCurrentPhoto').classList.remove('hidden');
                    } else {
                        document.getElementById('editCurrentPhoto').classList.add('hidden');
                    }
                    
                    document.getElementById('editServiceModal').style.display = 'flex';
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: data.message || 'Failed to load service details',
                        icon: 'error',
                        confirmButtonColor: '#d33'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    title: 'Error!',
                    text: 'Failed to load service details',
                    icon: 'error',
                    confirmButtonColor: '#d33'
                });
            });
    }

    function submitEditService(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        
        fetch('../functions/update_service.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: 'Success!',
                    text: 'Service updated successfully',
                    icon: 'success',
                    confirmButtonColor: '#3085d6'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    title: 'Error!',
                    text: data.message || 'Failed to update service',
                    icon: 'error',
                    confirmButtonColor: '#d33'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error!',
                text: 'An unexpected error occurred',
                icon: 'error',
                confirmButtonColor: '#d33'
            });
        });
    }

    // Delete Service
    function deleteService(serviceId) {
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('../functions/delete_service.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ service_id: serviceId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire(
                            'Deleted!',
                            'Service has been deleted.',
                            'success'
                        ).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire(
                            'Error!',
                            data.message || 'Failed to delete service',
                            'error'
                        );
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire(
                        'Error!',
                        'An unexpected error occurred',
                        'error'
                    );
                });
            }
        });
    }
    </script>
</body>
</html>