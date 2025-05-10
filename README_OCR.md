# ID OCR Feature Installation Guide

This feature extracts data from government-issued IDs using Tesseract OCR. Here's how to set it up:

## Prerequisites

1. **Python 3.6+**
2. **Tesseract OCR**
3. **PHP with exec() enabled**

## Installation Steps

### 1. Install Tesseract OCR

#### Windows:
1. Download the installer from [https://github.com/UB-Mannheim/tesseract/wiki](https://github.com/UB-Mannheim/tesseract/wiki)
2. Run the installer and complete the installation
3. Make sure to check "Add to PATH" during installation
4. For easier setup, run the `scripts/install_tesseract_windows.bat` script

#### Linux (Ubuntu/Debian):
```bash
sudo apt update
sudo apt install tesseract-ocr
```

#### macOS:
```bash
brew install tesseract
```

### 2. Install Required Python Packages

```bash
pip install -r requirements.txt
```

### 3. Create Necessary Directories

Make sure your web server has write permissions to create and write to the `temp_uploads` directory:
```bash
mkdir temp_uploads
chmod 777 temp_uploads  # Adjust permissions as needed for security
```

### 4. PHP Configuration

Ensure that PHP's `exec()` function is enabled. Check your php.ini file and make sure:
- `disable_functions` does not include `exec`
- `safe_mode` is set to Off

## How It Works

1. When a user uploads an ID, the image is sent to a PHP script
2. The PHP script calls a Python script that uses Tesseract OCR
3. Advanced image preprocessing improves OCR accuracy:
   - Image deskewing (rotation correction)
   - Multiple enhancement techniques
   - Various Tesseract configurations
4. Extracted data is displayed under the ID upload field
5. Full name, address, and other information is detected

## Supported ID Types

The system is designed to work with various ID types including:
- Philippine government-issued IDs (SSS, GSIS, PhilHealth)
- Driver's License
- Passport
- Postal ID
- Voter's ID
- PRC ID
- Company/School IDs (with proper format)

## Troubleshooting

If you encounter issues:

1. **Tesseract not found**:
   - Ensure Tesseract is installed and in your PATH
   - On Windows, run the `install_tesseract_windows.bat` script

2. **Python errors**:
   - Verify Python 3.6+ is installed
   - Install required packages with `pip install -r requirements.txt`

3. **PHP exec() disabled**:
   - Check your PHP configuration
   - Contact your server administrator if needed

4. **Poor extraction quality**:
   - Ensure the ID image is clear and well-lit
   - Try a different angle or better lighting
   - Some ID types may work better than others 