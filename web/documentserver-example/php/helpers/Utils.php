<?php

namespace OnlineEditorsExamplePhp\Helpers;

use Exception;

/**
 * (c) Copyright Ascensio System SIA 2023
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class Utils
{
    /**
     * File uploading
     *
     * @param string $fileUri
     *
     * @throws Exception If file type is not supported or copy operation is unsuccessful
     *
     * @return null
     */
    public function doUpload($fileUri)
    {
        $fileUtility = new FileUtility();
        $_fileName = $fileUtility->getCorrectName($fileUri);

        // check if file extension is supported by the editor
        $ext = mb_strtolower('.' . pathinfo($_fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $fileUtility->getFileExts())) {
            throw new Exception("File type is not supported");
        }

        // check if the file copy operation is successful
        if (!@copy($fileUri, $fileUtility->getStoragePath($_fileName))) {
            $errors = error_get_last();
            $err = "Copy file error: " . $errors['type'] . "<br />\n" . $errors['message'];
            throw new Exception($err);
        }

        return $_fileName;
    }

    /**
     * Generate an error code table
     *
     * @param string $errorCode Error code
     *
     * @throws Exception If error code is unknown
     *
     * @return null
     */
    public function processConvServResponceError($errorCode)
    {
        $errorMessageTemplate = "Error occurred in the document service: ";
        $errorMessage = '';

        // add the error message to the error message template depending on the error code
        switch ($errorCode) {
            case -8:
                $errorMessage = $errorMessageTemplate . "Error document VKey";
                break;
            case -7:
                $errorMessage = $errorMessageTemplate . "Error document request";
                break;
            case -6:
                $errorMessage = $errorMessageTemplate . "Error database";
                break;
            case -5:
                $errorMessage = $errorMessageTemplate . "Incorrect password";
                break;
            case -4:
                $errorMessage = $errorMessageTemplate . "Error download error";
                break;
            case -3:
                $errorMessage = $errorMessageTemplate . "Error convertation error";
                break;
            case -2:
                $errorMessage = $errorMessageTemplate . "Error convertation timeout";
                break;
            case -1:
                $errorMessage = $errorMessageTemplate . "Error convertation unknown";
                break;
            case 0:  // if the error code is equal to 0, the error message is empty
                break;
            default: // default value for the error message
                $errorMessage = $errorMessageTemplate . "ErrorCode = " . $errorCode;
                break;
        }

        throw new Exception($errorMessage);
    }

    /**
     * Translation key to a supported form.
     *
     * @param string $expected_key Expected key
     *
     * @return string key
     */
    public function generateRevisionId($expected_key)
    {
        if (mb_strlen($expected_key) > 20) {
            $expected_key = crc32($expected_key);
        }  // if the expected key length is greater than 20, calculate the crc32 for it
        $key = preg_replace("[^0-9-.a-zA-Z_=]", "_", $expected_key);
        $key = mb_substr($key, 0, min([mb_strlen($key), 20]));  // the resulting key length is 20 or less
        return $key;
    }

    /**
     * Request for conversion to a service.
     *
     * @param string $document_uri         Uri for the document to convert
     * @param string $from_extension       Document extension
     * @param string $to_extension         Extension to which to convert
     * @param string $document_revision_id Key for caching on service
     * @param bool   $is_async             Perform conversions asynchronously
     * @param string   $filePass
     * @param string   $lang
     *
     * @return string request result of conversion
     */
    public function sendRequestToConvertService(
        $document_uri,
        $from_extension,
        $to_extension,
        $document_revision_id,
        $is_async,
        $filePass,
        $lang
    ) {
        if (empty($from_extension)) {
            $path_parts = pathinfo($document_uri);
            $from_extension = mb_strtolower($path_parts['extension']);
        }

        // if title is undefined, then replace it with a random guid
        $title = basename($document_uri);
        if (empty($title)) {
            $fileUtility = new FileUtility();
            $title = $fileUtility->guid();
        }

        if (empty($document_revision_id)) {
            $document_revision_id = $document_uri;
        }

        // generate document token
        $document_revision_id = $this->generateRevisionId($document_revision_id);

        $configManager = new ConfigManager();
        $urlToConverter = $configManager->getConfig("docServSiteUrl").
            $configManager->getConfig("docServConverterUrl");

        $arr = [
            "async" => $is_async,
            "url" => $document_uri,
            "outputtype" => trim($to_extension, '.'),
            "filetype" => trim($from_extension, '.'),
            "title" => $title,
            "key" => $document_revision_id,
            "password" => $filePass,
            "region" => $lang,
        ];

        // add header token
        $headerToken = "";
        $jwtHeader = $configManager->getConfig("docServJwtHeader") == "" ?
            "Authorization" : $configManager->getConfig("docServJwtHeader");

        $jwtManager = new JwtManager();
        if ($jwtManager->isJwtEnabled()) {
            $headerToken = $jwtManager->jwtEncode(["payload" => $arr]);
            $arr["token"] = $jwtManager->jwtEncode($arr);
        }

        $data = json_encode($arr);

        // request parameters
        $opts = ['http' => [
            'method' => 'POST',
            'timeout' => $configManager->getConfig("docServTimeout"),
            'header' => "Content-type: application/json\r\n" .
                "Accept: application/json\r\n" .
                (empty($headerToken) ? "" : $jwtHeader.": Bearer $headerToken\r\n"),
            'content' => $data,
        ],
        ];

        if (mb_substr($urlToConverter, 0, mb_strlen("https")) === "https") {
            if ($configManager->getConfig("docServVerifyPeerOff") === true) {
                $opts['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
            }
        }

        $context = stream_context_create($opts);
        $response_data = file_get_contents($urlToConverter, false, $context);

        return $response_data;
    }

    /**
     * The method is to convert the file to the required format.
     *
     * Example:
     * string convertedDocumentUri;
     * getConvertedUri("http://helpcenter.onlyoffice.com/content/GettingStarted.pdf",
     * ".pdf", ".docx", "http://helpcenter.onlyoffice.com/content/GettingStarted.pdf", false, out convertedDocumentUri);
     *
     * @param string $document_uri           Uri for the document to convert
     * @param string $from_extension         Document extension
     * @param string $to_extension           Extension to which to convert
     * @param string $document_revision_id   Key for caching on service
     * @param bool   $is_async               Perform conversions asynchronously
     * @param string $converted_document_uri Uri to the converted document
     * @param string $filePass               File pass
     * @param string $lang                   Language
     *
     * @throws Exception if an error occurs
     *
     * @return int percentage of completion of conversion
     */
    public function getConvertedUri(
        $document_uri,
        $from_extension,
        $to_extension,
        $document_revision_id,
        $is_async,
        &$converted_document_uri,
        $filePass,
        $lang
    ) {
        $converted_document_uri = "";
        $responceFromConvertService = sendRequestToConvertService(
            $document_uri,
            $from_extension,
            $to_extension,
            $document_revision_id,
            $is_async,
            $filePass,
            $lang
        );
        $json = json_decode($responceFromConvertService, true);

        // if an error occurs, then display an error message
        $errorElement = $json["error"];
        if ($errorElement != null && $errorElement != "") {
            processConvServResponceError($errorElement);
        }

        $isEndConvert = $json["endConvert"];
        $percent = $json["percent"];

        // if the conversion is completed successfully
        if ($isEndConvert != null && $isEndConvert == true) {
            // then get the file url
            $converted_document_uri = $json["fileUrl"];
            $percent = 100;
        } elseif ($percent >= 100) { // otherwise, get the percentage of conversion completion
            $percent = 99;
        }

        return $percent;
    }

    /**
     * Processing document received from the editing service.
     *
     * @param Response $document_response The result from editing service
     * @param string $response_uri      Uri to the converted document
     *
     * @throws Exception if an error occurs
     *
     * @return int percentage of completion of conversion
     */
    public function getResponseUri($document_response, &$response_uri)
    {
        $response_uri = "";
        $resultPercent = 0;

        if (!$document_response) {
            $errs = "Invalid answer format";
        }

        // if an error occurs, then display an error message
        $errorElement = $document_response->Error;
        if ($errorElement != null && $errorElement != "") {
            processConvServResponceError($document_response->Error);
        }

        $endConvert = $document_response->EndConvert;
        if ($endConvert != null && $endConvert == "") {
            throw new Exception("Invalid answer format");
        }

        // if the conversion is completed successfully
        if ($endConvert != null && mb_strtolower($endConvert) == true) {
            $fileUrl = $document_response->FileUrl;
            if ($fileUrl == null || $fileUrl == "") {
                throw new Exception("Invalid answer format");
            }

            // get the response file url
            $response_uri = $fileUrl;
            $resultPercent = 100;
        } else { // otherwise, get the percentage of conversion completion
            $percent = $document_response->Percent;

            if ($percent != null && $percent != "") {
                $resultPercent = $percent;
            }
            if ($resultPercent >= 100) {
                $resultPercent = 99;
            }
        }

        return $resultPercent;
    }
}