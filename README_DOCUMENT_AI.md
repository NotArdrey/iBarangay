# Google Document AI Setup and Security

## Introduction
This guide explains how to set up Google Document AI for the iBarangay application and how to securely manage your API credentials.

## Prerequisites
1. A Google Cloud Platform account
2. Access to the Google Cloud Console
3. Document AI API enabled in your Google Cloud project

## Setup Instructions

### 1. Create a Google Cloud Project (if you don't have one already)
- Go to the [Google Cloud Console](https://console.cloud.google.com/)
- Click "Create Project" and follow the instructions
- Make note of your Project ID

### 2. Enable the Document AI API
- In the Google Cloud Console, go to "APIs & Services" > "Library"
- Search for "Document AI API" and enable it

### 3. Create a Document AI Processor
- Go to the [Document AI Console](https://console.cloud.google.com/ai/document-ai)
- Create a new processor of type "ID Document Parser" (or the appropriate type for your needs)
- Make note of the processor ID (shown in the processor details)

### 4. Create a Service Account
- Go to "IAM & Admin" > "Service Accounts"
- Click "Create Service Account"
- Name your service account (e.g., "ibarangay-document-ai")
- Grant the role "Document AI Editor" to the service account
- Click "Create and Continue"

### 5. Generate and Download Service Account Key
- Find your service account in the list and click on it
- Go to the "Keys" tab
- Click "Add Key" > "Create new key"
- Choose "JSON" format and click "Create"
- The key file will be downloaded to your computer

### 6. Secure Your Credentials

**IMPORTANT: Never commit service account keys to your repository!**

To securely use your credentials:

1. Place the downloaded JSON file in a secure location outside your code repository
2. Create a `.env` file in your project root based on the `.env.example` template
3. Add the path to your credentials file and other configuration:

```
GOOGLE_APPLICATION_CREDENTIALS=/absolute/path/to/your/credentials.json
DOCUMENT_AI_PROJECT_ID=your-project-id
DOCUMENT_AI_LOCATION=us  # or your chosen location
DOCUMENT_AI_PROCESSOR_ID=your-processor-id
```

4. Add `.env` to your `.gitignore` file to prevent it from being committed

## Usage in Your Code

The application is already configured to use these environment variables. When deployed:

1. In development, use the `.env` file
2. In production, set environment variables on your server
3. For cloud hosting (like Google Cloud Run), use the platform's secret management system

## Security Best Practices

1. Rotate your service account keys regularly
2. Use the principle of least privilege - only grant necessary permissions
3. Monitor your service account activity
4. Consider using a secrets management service for production deployments