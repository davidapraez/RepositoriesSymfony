<?php

namespace App\Controller;

use Aws\S3\S3Client;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
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
        $imageResponses = [];
        $audioResponse = [];

        if ($videoFile) {
            $ffmpeg = FFMpeg::create();
            $video = $ffmpeg->open($videoFile);
            $duration = $video->getFFProbe()->format($videoFile)->get('duration');

            if ($duration > 120) {
                return new JsonResponse(['error' => 'Video duration exceeds 2 minutes'], Response::HTTP_BAD_REQUEST);
            }
            $s3Client = $this->initializeS3Client();
            $audioPath = $this->extractAudioFromVideo($videoFile);
            $audioUrl = $this->uploadAudioToS3($s3Client, $audioPath);
            $audioResponse = $this->sendAudioToTranscriptionApi($audioUrl);
            for ($i = 1; $i <= $duration; $i++) {
                $frameUrl = $this->extractAndUploadFrame($s3Client, $video, $i);
                $imageResponses[] = $this->sendToGoogleVisionApi($frameUrl);
            }
        
        $responseData = [
            'images' => $imageResponses,
            'audio' => $audioResponse
        ];
        } else {
            $responseData = ['error' => 'No video file uploaded'];
        }

        return new JsonResponse($responseData);
    }
    //Image Analize
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
        return "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";
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

    //Audio Analize
    private function extractAudioFromVideo($videoFile) {
        $ffmpeg = FFMpeg::create();
        $video = $ffmpeg->open($videoFile->getPathname());
        $audioPath = sys_get_temp_dir() . "/audio.mp3";
    
        // Utiliza la clase Mp3 directamente sin el prefijo FFMpeg\FFMpeg
        $audio_format = new Mp3();
    
        $video->save($audio_format, $audioPath);
    
        return $audioPath;
    }
    
    
    private function uploadAudioToS3($s3Client, $audioPath) {
        $key = 'assets/audio/' . basename($audioPath);
        $s3Client->putObject([
            'Bucket'     => $_ENV['AWS_BUCKET'],
            'Key'        => $key,
            'SourceFile' => $audioPath,
        ]);
        unlink($audioPath); // Eliminar el archivo temporal
    
        $bucket = $_ENV['AWS_BUCKET'];
        $region = $_ENV['AWS_DEFAULT_REGION'];
        return "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";
    }
    /**
 * Sends an audio URL to the transcription API and returns the response.
 * 
 * @param string $audioUrl
 * @return array|bool Response from the transcription API or error
 */
private function sendAudioToTranscriptionApi($audioUrl) {
    $ch = curl_init();
    $data = json_encode(['audioUrl' => $audioUrl]);

    // Reemplaza esta URL con la URL de tu API Node.js
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:3001/transcribe');
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

