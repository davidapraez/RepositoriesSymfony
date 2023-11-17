<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\USER;
use App\Form\PostType;
use CURLFile;
use DateTime;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Entity;
use FFMpeg\FFMpeg;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\Date;
use Google\Cloud\Vision\VisionClient;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature_Type;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\ImageSource;
use FFMpeg\Coordinate\TimeCode;
use Symfony\Component\Filesystem\Filesystem;




class PostController extends AbstractController
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
     $this->em=$em;   
    }

    #[Route('/', name: 'app_post')]
    public function index(Request $request): Response
    {
        $post=new Post();
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);
        if($form->isSubmitted()&&$form->isValid()){
            $user = $this->em->getRepository(User::class)->find(id:1);
            $post->setUSER($user);
            $this->em->persist($post);
            $this->em->flush();
            return $this->redirectToRoute(route:'app_post');
        }
        return $this->render('post/index.html.twig', ['form' => $form->createView()]);

    }

    
    #[Route('/analizeImage', name: 'show_image')]
public function showImage(Request $request) : Response{
    $credentialsFilePath = $this->getParameter('kernel.project_dir') . '/google.json';
    if (!file_exists($credentialsFilePath)) {
        throw $this->createNotFoundException('El archivo de credenciales no fue encontrado.');
    }
    $credentialsJson = file_get_contents($credentialsFilePath);
    if ($credentialsJson === false) {
        throw new \Exception('Error al leer el archivo de credenciales JSON.');
    }
    $credentials = json_decode($credentialsJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Error al decodificar las credenciales JSON.');
    }

    try {
        $vision = new VisionClient(["keyFile" => $credentials]);
        $url = $request->request->get('url');
        $foto = null;
        $parametersImageApi=['LABEL_DETECTION',
        'SAFE_SEARCH_DETECTION', 'TEXT_DETECTION',
        'FACE_DETECTION','OBJECT_LOCALIZATION','LOGO_DETECTION',
        'DOCUMENT_TEXT_DETECTION','IMAGE_PROPERTIES','WEB_DETECTION'];
        if ($url == ""){
            $imageFile = $request->files->get('image');
            $image = file_get_contents($imageFile);
            $foto = $vision->image($image, $parametersImageApi);
        } else{
            $foto = $vision->image($url, $parametersImageApi);
        }
            
        $result = $vision->annotate($foto);
        $data = [];
        $logos = [];
        $text = [];
        $emotions = [];
        $webEntitiesArray = [];
        $dominantColorsArray = [];
        $objects = [];

        if ($result->info()['localizedObjectAnnotations']) {
            foreach ($result->info()['localizedObjectAnnotations'] as $objetc) {
        
                $score = isset($objetc['score']) ? $objetc['score'] : null;
                $name = isset($objetc['name']) ? $objetc['name'] : null;
            
                $objects[] = [
                    'score' => $score,
                    'name' => $name,
                ];
            }
        }
        
        if($result->imageProperties()->colors()){
            foreach ($result->imageProperties()->colors() as $colorInfo) {
    
                $color = $colorInfo['color'];
                $score = $colorInfo['score'];
                $pixelFraction = $colorInfo['pixelFraction'];
    
                $dominantColorsArray[] = [
                    'red' => $color['red'],
                    'green' => $color['green'],
                    'blue' => $color['blue'],
                    'score' => $score,
                    'pixelFraction' => $pixelFraction,
                ];
            }
        }

        if ($result) {
            foreach ($result->labels() as $label) {
                $description = $label->info()['description'];
                $score = $label->info()['score'];
               
                $data[] = [
                    'description' => $description,
                    'score' => $score,
                ];
            }
        }
        if ($result->web()) {
            foreach ($result->web()->entities() as $webEntity) {
                $webEntityInfo = $webEntity->info();
            
                $entityId = isset($webEntityInfo['entityId']) ? $webEntityInfo['entityId'] : null;
                $score = isset($webEntityInfo['score']) ? $webEntityInfo['score'] : null;
                $description = isset($webEntityInfo['description']) ? $webEntityInfo['description'] : null;
            
                $webEntitiesArray[] = [
                    'entityId' => $entityId,
                    'score' => $score,
                    'description' => $description,
                ];
            }
        }
        
        if ($result->faces()){
            foreach ($result->faces() as $face) {
                $emotions[] = [
                    'joy' => $face->info()['joyLikelihood'],
                    'sorrow' => $face->info()['sorrowLikelihood'],
                    'anger' => $face->info()['angerLikelihood'],
                    'surprise' => $face->info()['surpriseLikelihood'],
                    'underExposed' => $face->info()['underExposedLikelihood'],
                    'blurred' => $face->info()['blurredLikelihood'],
                    'headwear' => $face->info()['headwearLikelihood'],
                ];
            }
        }
       
        if ($result->logos()) {
            foreach ($result->logos() as $logo) {
                $logos[] = $logo->description();
            }
        } 
        if ($result->text()) {
            foreach ($result->text() as $texto) {
                $text[] = $texto->description();
            }
        } 
        $safeSearchInfo = $result->safeSearch()->info();

        $responseData = [
            'labels' => $data,
            'safeSearch' => [
                'adult' => $safeSearchInfo['adult'],
                'spoof' => $safeSearchInfo['spoof'],
                'medical' => $safeSearchInfo['medical'],
                'violence' => $safeSearchInfo['violence'],
                'racy' => $safeSearchInfo['racy'],
            ],
            'faces' => $emotions,
            'logos' => $logos,
            'text' => $text,
            'imageProperties' => $dominantColorsArray,
            'objects' => $objects,
            'web' => $webEntitiesArray,
        ];
        return new JsonResponse(['data' =>  $responseData]);
        
    } catch (\Exception $e) {
        throw $e;
        
    }
}

}

