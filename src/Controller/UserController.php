<?php

namespace App\Controller;

use App\Entity\USER;
use App\Form\USERType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends AbstractController
{
    private $em;
    public function __construct(EntityManagerInterface $em)
    {
     $this->em=$em;   
    }

    #[Route('/registration', name: 'UserRegistration')]
    public function UserRegistration(Request $request,UserPasswordHasherInterface $passwordHasher): Response
    {


        $user=new USER();
        $registration_form=$this->createForm(USERType::class,$user);
        $registration_form->handleRequest($request);
        if($registration_form->isSubmitted()&&$registration_form->isValid()){
                
                $plaintextPassword = $registration_form->get('password')->getData();
                $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $plaintextPassword
        );
                $user->setPassword($hashedPassword);
                $user->setRoles(['ROLE_USER']);
                $this->em->persist($user);
                $this->em->flush();
                return $this->redirectToRoute(route:'UserRegistration');
        }
        return $this->render('user/index.html.twig', [
            'registration_form'=>$registration_form->createView(),
        ]);
    }
}
