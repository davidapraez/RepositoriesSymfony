<?php

namespace App\Controller;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AnalizeVideoController extends AbstractController
{
    /**
     * Analyzes a video by extracting frames and processing them.
     * 
     * @Route("/analyzeVideo", name="analize_video")
     *
     * @param Request $request
     * @return Response
     */
    #[Route('/analyzeVideo', name: 'analize_video')]
    public function analizeVideo(Request $request): Response {
        $videoFile = $request->files->get('video');
        $videoFile = $request->files->get('video');
if (!$videoFile) {
    return new JsonResponse(['error' => 'No video file uploaded'], Response::HTTP_BAD_REQUEST);
}

        $responses = [];

        if ($videoFile) {
            $ffmpeg = FFMpeg::create();
            $video = $ffmpeg->open($videoFile);
            $duration = $video->getFFProbe()->format($videoFile)->get('duration');

            if ($duration > 120) {
                return new JsonResponse(['error' => 'Video duration exceeds 2 minutes'], Response::HTTP_BAD_REQUEST);
            }  

            $s3Client = $this->initializeS3Client();

            for ($i = 1; $i <= $duration; $i++) {
                $uploadResult = $this->extractAndUploadFrame($s3Client, $video, $i);
                $responses[] = $this->sendToGoogleVisionApi($uploadResult['url']);
    
                // Eliminar la imagen de S3 después del análisis
                $this->deleteImageFromS3($s3Client, $_ENV['AWS_BUCKET'], $uploadResult['key']);
            }

            $responseData = ['data' => $responses];
        } else {
            $responseData = ['error' => 'No video file uploaded'];
        }

        return new JsonResponse($responseData);
    }

    /**
     * Initializes and returns an S3 client.
     * 
     * @return S3Client
     */
    private function initializeS3Client() {
        return new S3Client([
            'version' => 'latest',
            'region'  => $_ENV['AWS_DEFAULT_REGION'],
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
            ],
        ]);
    }

    /**
     * Extracts a frame from a video at a given second and uploads it to S3.
     * 
     * @param S3Client $s3Client
     * @param \FFMpeg\Media\Video $video
     * @param int $second
     * @return string URL of the uploaded frame
     */
    private function extractAndUploadFrame($s3Client, $video, $second) {
        $timecode = TimeCode::fromSeconds($second);
        $framePath = sys_get_temp_dir() . "/frame_$second.jpg";
        $video->frame($timecode)->save($framePath);

        $key = 'assets/imageVideoCampaignTester/' . $second . '.jpg';
        $s3Client->putObject([
            'Bucket'     => $_ENV['AWS_BUCKET'],
            'Key'        => $key,
            'SourceFile' => $framePath,
        ]);
        unlink($framePath); // Delete the temporary file

        $bucket = $_ENV['AWS_BUCKET'];
        $region = $_ENV['AWS_DEFAULT_REGION'];
        $frameUrl = "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";

        return ['url' => $frameUrl, 'key' => $key];
    }

    private function deleteImageFromS3($s3Client, $bucket, $key) {
        try {
            $s3Client->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $key
            ]);
        } catch (S3Exception $e) {
            // Log the error message for future reference
            $errorMessage = sprintf(
                'Error deleting object from S3. Bucket: %s, Key: %s. Error message: %s',
                $bucket,
                $key,
                $e->getMessage()
            );
            error_log($errorMessage);
        }
    }

    /**
     * Sends a frame URL to Google Vision API and returns the response.
     * 
     * @param string $frameUrl
     * @return array|bool Response from Google Vision API or error
     */
    private function sendToGoogleVisionApi($frameUrl) {
        $ch = curl_init();
        $data = json_encode(['url' => $frameUrl]);

        curl_setopt($ch, CURLOPT_URL, $_ENV['LAMBDA_URL']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        curl_close($ch);
        return json_decode($response, true);
    }
}

