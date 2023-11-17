<?php
namespace App\Controller;

use Aws\S3\S3Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AnalizeImageController extends AbstractController
{
    /**
     * Handles the image analysis request.
     * 
     * @Route("/analyzeImage", name="analize_image")
     *
     * @param Request $request
     * @return Response
     */
    #[Route('/analyzeImage', name: 'analize_image')]
    public function analyzeImage(Request $request): Response {
        try {
            $url = $request->request->get('url');
            $uploadedImageUrl = null;

            // Initialize AWS S3 client
            $s3Client = new S3Client([
                'version' => 'latest',
                'region'  => $_ENV['AWS_DEFAULT_REGION'],
                'credentials' => [
                    'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
                    'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
                ],
            ]);

            // Process URL or file upload
            if (!empty($url)) {
                $this->validateUrl($url);
                $uploadedImageUrl = $url;
            } else {
                $uploadedImageUrl = $this->uploadImageAndGetUrl($request, $s3Client);
            }

            // Send image to Lambda for processing
            $lambdaResponse = $this->sendToLambda($uploadedImageUrl);

            // Delete the uploaded image from S3 if it was uploaded
            $this->deleteImageFromS3($request, $s3Client);

            // Return the Lambda response
            return new JsonResponse([
                'data' => $lambdaResponse
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Validates the provided URL.
     *
     * @param string $url
     * @throws \Exception if the URL is invalid
     */
    private function validateUrl($url) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \Exception("Invalid URL");
        }
    }

    /**
     * Uploads an image to AWS S3 and returns its URL.
     *
     * @param Request $request
     * @param S3Client $s3Client
     * @return string
     * @throws \Exception if no image is provided
     */
    private function uploadImageAndGetUrl(Request $request, S3Client $s3Client) {
        $imageFile = $request->files->get('image');
        if ($imageFile) {
            $key = 'assets/imageCampaignTester/' . uniqid() . '_' . $imageFile->getClientOriginalName();
            $result = $s3Client->putObject([
                'Bucket'     => $_ENV['AWS_BUCKET'],
                'Key'        => $key,
                'SourceFile' => $imageFile->getPathname(),
            ]);
            return $result->get('ObjectURL');
        }

        throw new \Exception("No image data provided");
    }

    /**
     * Deletes an uploaded image from AWS S3.
     *
     * @param Request $request
     * @param S3Client $s3Client
     */
    private function deleteImageFromS3(Request $request, S3Client $s3Client) {
        if ($request->files->get('image')) {
            $s3Client->deleteObject([
                'Bucket' => $_ENV['AWS_BUCKET'],
                'Key'    => 'assets/imageCampaignTester/' . uniqid() . '_' . $request->files->get('image')->getClientOriginalName(),
            ]);
        }
    }

    /**
     * Sends the image URL to a Lambda function and returns the response.
     *
     * @param string $imageUrl
     * @return array|bool
     */
    private function sendToLambda($imageUrl) {
        $ch = curl_init();

        $data = json_encode(['url' => $imageUrl]);
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