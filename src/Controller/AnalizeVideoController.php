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
        if (!$videoFile) {
            return $this->json(['error' => 'No video file uploaded'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $authUser = $this->getUser();
        $authUserId = $authUser->getId();

        $ffmpeg = FFMpeg::create();
        $video = $ffmpeg->open($videoFile->getRealPath());
        $duration = $video->getFFProbe()->format($videoFile->getRealPath())->get('duration');

        if ($duration > 120) {
            return $this->json(['error' => 'Video duration exceeds 2 minutes'], JsonResponse::HTTP_BAD_REQUEST);
        }  

        $s3Client = $this->initializeS3Client();
        $responses = []; 
        $region = $_ENV['AWS_DEFAULT_REGION'];
        $bucket = $_ENV['AWS_BUCKET'];
        for ($i = 1; $i <= $duration; $i++) {
            try {
                $key = $this->extractAndUploadFrame($s3Client, $video, $i, $authUserId);
                $frameUrl = "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";
                $responses[] = $this->sendToGoogleVisionApi($frameUrl);
                $this->deleteImageFromS3($s3Client, $bucket, $key);
            } catch (\Exception $e) {
                return $this->json(['error' => 'An error occurred while processing the video: ' . $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return $this->json(['data' => $responses]);
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
    private function extractAndUploadFrame($s3Client, $video, $second, $userId): string {
        $timecode = TimeCode::fromSeconds($second);
        $framePath = sys_get_temp_dir() . "/frame_$second.jpg";
        $video->frame($timecode)->save($framePath);

        $key = 'assets/imageVideoCampaignTester/' . $userId . '/' . $second . '.jpg';
        try {
            $s3Client->putObject([
                'Bucket'     => $_ENV['AWS_BUCKET'],
                'Key'        => $key,
                'SourceFile' => $framePath,
            ]);
        } catch (S3Exception $e) {
            throw new \Exception("Error uploading frame to S3: " . $e->getMessage());
        } finally {
            unlink($framePath);
        }

        return $key;
    }

    private function deleteImageFromS3($s3Client, $bucket, $key) {
        try {
            $s3Client->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $key
            ]);
        } catch (S3Exception $e) {
            // Manejar la excepción si es necesario
            echo "There was an error deleting the file: " . $e->getMessage();
        }
    }

    /**
     * Sends a frame URL to Google Vision API and returns the response.
     * 
     * @param string $frameUrl
     * @return array|bool Response from Google Vision API or error
     */
    private function sendToGoogleVisionApi($frameUrl): array {
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
            return ['error' => 'Error connecting to Google Vision API: ' . $error];
        }

        curl_close($ch);
        return json_decode($response, true);
    }
}

