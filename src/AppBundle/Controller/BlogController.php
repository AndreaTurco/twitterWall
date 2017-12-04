<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AppBundle\Controller;

use AppBundle\Entity\Comment;
use AppBundle\Entity\Post;
use AppBundle\Events;
use AppBundle\Form\CommentType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use AppBundle\Utils\TwitterAPIExchange;

/**
 * Controller used to manage blog contents in the public part of the site.
 *
 * @Route("/blog")
 *
 * @author Ryan Weaver <weaverryan@gmail.com>
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
class BlogController extends Controller
{
    /**
     * @Route("/", defaults={"page": "1", "_format"="html"}, name="blog_index")
     * @Route("/rss.xml", defaults={"page": "1", "_format"="xml"}, name="blog_rss")
     * @Route("/page/{page}", defaults={"_format"="html"}, requirements={"page": "[1-9]\d*"}, name="blog_index_paginated")
     * @Method("GET")
     * @Cache(smaxage="10")
     *
     * NOTE: For standard formats, Symfony will also automatically choose the best
     * Content-Type header for the response.
     * See https://symfony.com/doc/current/quick_tour/the_controller.html#using-formats
     *
     * @throws \Exception
     */
    public function indexAction($page, $_format)
    {
        $em = $this->getDoctrine()->getManager();
        $posts = $em->getRepository(Post::class)->findLatest($page);

        // Every template name also has two extensions that specify the format and
        // engine for that template.
        // See https://symfony.com/doc/current/templating.html#template-suffix
        return $this->render('blog/index.'.$_format.'.twig', ['posts' => $posts]);
    }

    /**
     * @Route("/posts/{slug}", name="blog_post")
     * @Method("GET")
     *
     * NOTE: The $post controller argument is automatically injected by Symfony
     * after performing a database query looking for a Post with the 'slug'
     * value given in the route.
     * See https://symfony.com/doc/current/bundles/SensioFrameworkExtraBundle/annotations/converters.html
     */
    public function postShowAction(Post $post)
    {
        // Symfony provides a function called 'dump()' which is an improved version
        // of the 'var_dump()' function. It's useful to quickly debug the contents
        // of any variable, but it's not available in the 'prod' environment to
        // prevent any leak of sensitive information.
        // This function can be used both in PHP files and Twig templates. The only
        // requirement is to have enabled the DebugBundle.
        if ('dev' === $this->getParameter('kernel.environment')) {
            dump($post, $this->getUser(), new \DateTime());
        }

        return $this->render('blog/post_show.html.twig', ['post' => $post]);
    }

    /**
     * @Route("/twitter", name="twitter_wall")
     * @Method("GET")
     *
     */
    public function twitterAction()
    {
        $settings = array(
            'oauth_access_token' => "295837707-7WgelWt3xpjGoAvRAhrNfk8GHc2kKpB5iKfPCNIA",
            'oauth_access_token_secret' => "pzmfAeaTBokdEpe7RCV6bLJzA0O2EQUxxA9agDDpYRUYm",
            'consumer_key' => "uptS4yDB5gDJS419aeCdyJNtO",
            'consumer_secret' => "j8W2t14ripKYwcNEuf4St9OyePDNeI94zdypkpdwtl0kFMVnbJ"
        );

        // $url = 'https://api.twitter.com/1.1/blocks/create.json';
        $url = 'https://api.twitter.com/1.1/search/tweets.json';
        $requestMethod = 'GET';
        // $requestMethod = 'POST';

        $getfields = '?q=ferrari&result_type=popular';

        // $postfields = array(
        //     'screen_name' => 'usernameToBlock',
        //     'skip_status' => '1'
        // );

        // $twitter = new TwitterAPIExchange($settings);
        // var_dump($twitter->buildOauth($url, $requestMethod)
        //     ->setPostfields($postfields)
        //     ->performRequest());

        $twitter = new TwitterAPIExchange($settings);
        try {
            echo($twitter->setGetfield($getfields)->buildOauth($url, $requestMethod)->performRequest());
        } catch (\Exception $e) {
        }
        exit;
    }

    /**
     * @Route("/comment/{postSlug}/new", name="comment_new")
     * @Method("POST")
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     * @ParamConverter("post", options={"mapping": {"postSlug": "slug"}})
     *
     * NOTE: The ParamConverter mapping is required because the route parameter
     * (postSlug) doesn't match any of the Doctrine entity properties (slug).
     * See https://symfony.com/doc/current/bundles/SensioFrameworkExtraBundle/annotations/converters.html#doctrine-converter
     */
    public function commentNewAction(Request $request, Post $post, EventDispatcherInterface $eventDispatcher)
    {
        $comment = new Comment();
        $comment->setAuthor($this->getUser());
        $post->addComment($comment);

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($comment);
            $em->flush();

            // When triggering an event, you can optionally pass some information.
            // For simple applications, use the GenericEvent object provided by Symfony
            // to pass some PHP variables. For more complex applications, define your
            // own event object classes.
            // See https://symfony.com/doc/current/components/event_dispatcher/generic_event.html
            $event = new GenericEvent($comment);

            // When an event is dispatched, Symfony notifies it to all the listeners
            // and subscribers registered to it. Listeners can modify the information
            // passed in the event and they can even modify the execution flow, so
            // there's no guarantee that the rest of this controller will be executed.
            // See https://symfony.com/doc/current/components/event_dispatcher.html
            $eventDispatcher->dispatch(Events::COMMENT_CREATED, $event);

            return $this->redirectToRoute('blog_post', ['slug' => $post->getSlug()]);
        }

        return $this->render('blog/comment_form_error.html.twig', [
            'post' => $post,
            'form' => $form->createView(),
        ]);
    }

    /**
     * This controller is called directly via the render() function in the
     * blog/post_show.html.twig template. That's why it's not needed to define
     * a route name for it.
     *
     * The "id" of the Post is passed in and then turned into a Post object
     * automatically by the ParamConverter.
     *
     * @param Post $post
     *
     * @return Response
     */
    public function commentFormAction(Post $post)
    {
        $form = $this->createForm(CommentType::class);

        return $this->render('blog/_comment_form.html.twig', [
            'post' => $post,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/search", name="blog_search")
     * @Method("GET")
     *
     * @return Response|JsonResponse
     */
    public function searchAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->render('blog/search.html.twig');
        }

        $query = $request->query->get('q', '');
        $posts = $this->getDoctrine()->getRepository(Post::class)->findBySearchQuery($query);

        $results = [];
        foreach ($posts as $post) {
            $results[] = [
                'title' => htmlspecialchars($post->getTitle()),
                'summary' => htmlspecialchars($post->getSummary()),
                'url' => $this->generateUrl('blog_post', ['slug' => $post->getSlug()]),
            ];
        }

        return $this->json($results);
    }
}
