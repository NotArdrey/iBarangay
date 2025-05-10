#!/usr/bin/env python3
import cv2
import pytesseract
import re
import json
import sys
import os
import numpy as np
from PIL import Image

# For Windows, set Tesseract path if it's not in PATH
if os.name == 'nt':  # Check if running on Windows
    tesseract_possible_paths = [
        r'C:\Program Files\Tesseract-OCR\tesseract.exe',
        r'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe',
        r'C:\Tesseract-OCR\tesseract.exe'
    ]
    
    for path in tesseract_possible_paths:
        if os.path.exists(path):
            pytesseract.pytesseract.tesseract_cmd = path
            break

# Check if Tesseract is installed
try:
    version = pytesseract.get_tesseract_version()
except Exception as e:
    print(json.dumps({"error": f"Tesseract not found. Error: {str(e)}"}))
    sys.exit(1)

def enhance_image(image):
    """Apply multiple image processing techniques to improve OCR accuracy"""
    # Convert to grayscale if not already
    if len(image.shape) == 3:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    else:
        gray = image.copy()
    
    # Apply bilateral filter to preserve edges while reducing noise
    filtered = cv2.bilateralFilter(gray, 11, 17, 17)
    
    # Apply adaptive thresholding to handle varying lighting conditions
    thresh = cv2.adaptiveThreshold(filtered, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, 
                                  cv2.THRESH_BINARY, 11, 2)
    
    # Remove noise with morphological operations
    kernel = np.ones((1, 1), np.uint8)
    opening = cv2.morphologyEx(thresh, cv2.MORPH_OPEN, kernel)
    
    # Apply CLAHE for better contrast
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)
    
    return {
        'gray': gray,
        'filtered': filtered,
        'threshold': thresh,
        'opening': opening,
        'enhanced': enhanced
    }

def correct_skew(image, delta=1, limit=5):
    """Detect and correct skew (rotation) in images"""
    # Detect edges
    edges = cv2.Canny(image, 50, 150, apertureSize=3)
    
    # Use Hough Line Transform
    lines = cv2.HoughLines(edges, 1, np.pi/180, 100)
    
    if lines is None or len(lines) == 0:
        return image
    
    # Calculate angles
    angles = []
    for line in lines:
        rho, theta = line[0]
        if theta < np.pi * 0.25 or (theta > np.pi * 0.75 and theta < np.pi * 1.25):
            angle = theta * 180 / np.pi
            if angle > 90:
                angle = angle - 180
            angles.append(angle)
    
    if not angles:
        return image
    
    # Get median angle
    median_angle = np.median(angles)
    
    # Correct if skew is within limits
    if abs(median_angle) < limit:
        (h, w) = image.shape[:2]
        center = (w // 2, h // 2)
        M = cv2.getRotationMatrix2D(center, median_angle, 1.0)
        
        rotated = cv2.warpAffine(image, M, (w, h), 
                               flags=cv2.INTER_CUBIC, 
                               borderMode=cv2.BORDER_REPLICATE)
        return rotated
    
    return image

def process_image(image_path):
    """Process image with multiple techniques for optimal OCR results"""
    # Read image
    original = cv2.imread(image_path)
    if original is None:
        raise Exception(f"Could not read image: {image_path}")
    
    # Resize if too large
    height, width = original.shape[:2]
    max_dimension = 1800
    if max(height, width) > max_dimension:
        scale_factor = max_dimension / max(height, width)
        new_width = int(width * scale_factor)
        new_height = int(height * scale_factor)
        original = cv2.resize(original, (new_width, new_height))
    
    # Correct skew
    deskewed = correct_skew(original)
    
    # Enhance image
    enhanced_images = enhance_image(deskewed)
    
    # Try different configurations
    results = []
    configs = [
        '--oem 3 --psm 3',  # Auto page segmentation
        '--oem 3 --psm 4',  # Assume single column of text
        '--oem 3 --psm 6',  # Assume single uniform block of text
        '--oem 3 --psm 11'  # Sparse text - more accurate
    ]
    
    # Run OCR on each enhanced image with different configs
    for img_name, img in enhanced_images.items():
        for config in configs:
            text = pytesseract.image_to_string(img, config=config)
            if text.strip():
                results.append(text)
    
    # Direct OCR on deskewed image
    direct_text = pytesseract.image_to_string(deskewed)
    
    # Combine all results
    all_text = "\n".join(results) + "\n" + direct_text
    
    return all_text

def clean_text(text):
    """Clean and normalize OCR text for better pattern matching"""
    if not text:
        return ""
    
    # Replace multiple spaces and newlines
    cleaned = re.sub(r'\s+', ' ', text)
    
    # Remove unwanted characters but keep essential punctuation
    cleaned = re.sub(r'[^\w\s,.:-]', '', cleaned)
    
    return cleaned.strip()

def extract_name(text):
    """Extract full name from ID text"""
    # Various name patterns
    name_patterns = [
        # Regular labeled name fields
        r"(?:Name|Full Name|FULL NAME)[:\s]*([A-Z][a-zA-Z\s]+(?:[,-]\s*[A-Z][a-zA-Z\s]+)*)",
        
        # Last, First Middle format
        r"(?:Name|Full Name|FULL NAME)[:\s]*([A-Z][a-zA-Z\s]+),\s*([A-Z][a-zA-Z\s]+)(?:\s+([A-Z][a-zA-Z\s\.]+))?",
        
        # Separate labeled fields
        r"(?:Last Name|Surname|SURNAME)[:\s]*([A-Z][a-zA-Z\s]+).*?(?:First Name|Given Name|FIRST NAME)[:\s]*([A-Z][a-zA-Z\s]+)(?:.*?(?:Middle Name|MI|MIDDLE NAME)[:\s]*([A-Z][a-zA-Z\s\.]+))?",
        
        # Common ID pattern without labels
        r"([A-Z][A-Za-z]+)\s*,\s*([A-Z][A-Za-z]+)(?:\s+([A-Z])[\.A-Za-z]*)?",
        
        # Standalone name-like patterns
        r"([A-Z][a-zA-Z]{2,}\s+[A-Z][a-zA-Z]{2,}(?:\s+[A-Z][a-zA-Z]{0,}))"
    ]
    
    # Try each pattern
    for pattern in name_patterns:
        matches = re.findall(pattern, text, re.IGNORECASE)
        if matches:
            if isinstance(matches[0], tuple):
                # Last, First, Middle format
                if len(matches[0]) >= 2:
                    last = matches[0][0].strip()
                    first = matches[0][1].strip()
                    middle = matches[0][2].strip() if len(matches[0]) > 2 else ""
                    
                    # Format name properly
                    full_name = f"{first} {middle} {last}".strip()
                    full_name = re.sub(r'\s+', ' ', full_name)
                    
                    return {
                        "full_name": full_name.title(),
                        "first_name": first.title(),
                        "middle_name": middle.title(),
                        "last_name": last.title()
                    }
            else:
                # Full name format
                full_name = matches[0].strip()
                full_name = re.sub(r'\s+', ' ', full_name)
                
                # Try to split into components if possible
                name_parts = full_name.split()
                if len(name_parts) >= 2:
                    first_name = name_parts[0]
                    last_name = name_parts[-1]
                    middle_name = " ".join(name_parts[1:-1]) if len(name_parts) > 2 else ""
                    
                    return {
                        "full_name": full_name.title(),
                        "first_name": first_name.title(),
                        "middle_name": middle_name.title(),
                        "last_name": last_name.title()
                    }
    
    # If no match, return empty
    return {
        "full_name": "",
        "first_name": "",
        "middle_name": "",
        "last_name": ""
    }

def extract_address(text):
    """Extract address from ID text"""
    # Common address patterns
    address_patterns = [
        r"(?:Address|Tirahan|PERMANENT ADDRESS|PRESENT ADDRESS|HOME ADDRESS)[:\s]+(.+?)(?=\n\n|\n[A-Z]|$)",
        r"(?i)address[:\s]*(.+?)(?=\n|\n\n|\n[A-Z]|$)",
        r"(?i)permanent\s+address[:\s]*(.+?)(?=\n|\n\n|\n[A-Z]|$)",
        r"(?i)residence[:\s]*(.+?)(?=\n|\n\n|\n[A-Z]|$)"
    ]
    
    # Try each pattern
    for pattern in address_patterns:
        match = re.search(pattern, text)
        if match:
            address = match.group(1).strip()
            address = re.sub(r'\s+', ' ', address)
            return address
    
    return ""

def extract_birth_date(text):
    """Extract birth date from ID text"""
    # Common date patterns (MM/DD/YYYY, DD/MM/YYYY, YYYY/MM/DD, etc.)
    date_patterns = [
        r"(?:Date of Birth|Birth Date|DOB|BIRTH DATE)[:\s]*(\d{1,2}[/-]\d{1,2}[/-]\d{2,4})",
        r"(?:Date of Birth|Birth Date|DOB|BIRTH DATE)[:\s]*(\d{1,2}\s+[A-Za-z]+\s+\d{2,4})",
        r"(?i)born\s+on[:\s]*(\d{1,2}[/-]\d{1,2}[/-]\d{2,4})",
        r"(?i)birth\s*date[:\s]*(\d{1,2}[/-]\d{1,2}[/-]\d{2,4})"
    ]
    
    # Try each pattern
    for pattern in date_patterns:
        match = re.search(pattern, text)
        if match:
            return match.group(1).strip()
    
    return ""

def extract_id_data(image_path):
    """Extract all relevant data from ID image"""
    try:
        # Process and get text from image
        raw_text = process_image(image_path)
        clean_content = clean_text(raw_text)
        
        # Extract individual data elements
        name_data = extract_name(raw_text)
        address = extract_address(raw_text)
        birth_date = extract_birth_date(raw_text)
        
        # Combine all data
        result = {
            "full_name": name_data["full_name"],
            "first_name": name_data["first_name"],
            "middle_name": name_data["middle_name"],
            "last_name": name_data["last_name"],
            "address": address,
            "birth_date": birth_date,
            "raw_text": clean_content
        }
        
        return result
        
    except Exception as e:
        return {"error": str(e)}

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print(json.dumps({"error": "Usage: extract_id_data.py <image_path>"}))
        sys.exit(1)
    
    image_path = sys.argv[1]
    if not os.path.exists(image_path):
        print(json.dumps({"error": f"Image file not found: {image_path}"}))
        sys.exit(1)
    
    # Extract and output data as JSON
    result = extract_id_data(image_path)
    print(json.dumps(result)) 