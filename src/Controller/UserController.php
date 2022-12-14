<?php

namespace App\Controller;

use App\Entity\User;
use App\Exception\BilemoException;
use App\Service\UserService;
use App\Service\ValidatorService;
use Exception;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Annotations as OA;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Webmozart\Assert\Assert;

class UserController extends AbstractController
{
    public function __construct(private readonly UserService $userService, private readonly SerializerInterface $serializer, private readonly TagAwareCacheInterface $cache)
    {
    }

    /**
     *   This method return the list of all users.
     *
     * @OA\Response(
     *     response=200,
     *     description="Return the list of all users",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=User::class, groups={"userDetails"}))
     *     )
     * )
     * @OA\Tag(name="User")
     *
     * @return JsonResponse
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Route('/api/users', name: 'users', methods: ['GET'])]
    public function getAllUser(): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $userService = $this->userService;

        $idCache = "getAllUsers";
        $jsonUsers = $this->cache->get($idCache, function (ItemInterface $item) use ($userService, $admin) {
            $item->tag("usersCache");
            $users = $userService->getAllUserOfTheSameCustomer($admin);
            $context = SerializationContext::create()->setGroups(["userList", "getUser"]);
            return $this->serializer->serialize($users, 'json', $context);
        });


        return new JsonResponse($jsonUsers, Response::HTTP_OK, [], true);
    }

    /**
     *   This method return the detail of a user.
     *
     * @OA\Response(
     *     response=200,
     *     description="Return the detail of a user",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=User::class, groups={"userDetails"}))
     *     )
     * )
     * @OA\Parameter(
     *     name="id",
     *     in="path",
     *     description="The identifiant of a user",
     *     @OA\Schema(type="integer")
     * )
     * @OA\Tag(name="User")
     * @param int $id
     * @return JsonResponse
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[
        Route('/api/users/{id}', name: 'detail_user', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getDetailUser(int $id): JsonResponse
    {
        Assert::integer($id, "The product Id must be an integer !");

        $idCache = "getDetailsUser" . $id;
        $userService = $this->userService;

        $jsonUser = $this->cache->get($idCache, function (ItemInterface $item) use ($userService, $id) {
            echo "Pas encore en cache";
            $item->tag("usersCache");
            $user = $userService->getUserDetail($id);
            if (empty($user)) throw new BilemoException("This user dont exists", Response::HTTP_NOT_FOUND);
            $this->denyAccessUnlessGranted('CAN_ACCESS', $user);
            $context = SerializationContext::create()->setGroups(["userDetails", "getUser"]);
            return $this->serializer->serialize($user, 'json', $context);
        });

        return new JsonResponse($jsonUser, Response::HTTP_OK, [], true);
    }

    /**
     *   This method create a user.
     *
     * @OA\Response(
     *     response=200,
     *     description="Return create a user",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items()
     *     )
     * )
     * @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *              @OA\Schema(
     *               type="object",
     *               @OA\Property(
     *                   property="firstname",
     *                   description="firstname",
     *                   type="string",
     *                   example="Jean"
     *               ),
     *               @OA\Property(
     *                   property="lastname",
     *                   description="lastname",
     *                   type="string",
     *                   example="Dupont"
     *               ),
     *                  @OA\Property(
     *                   property="email",
     *                   description="email",
     *                   type="string",
     *                   example="jean.dupont67@gmail.com"
     *               ),
     *               @OA\Property(
     *                   property="password",
     *                   description="User password",
     *                   type="string",
     *                   example="larapoints123"
     *               ),
     *           )
     *         )
     *     )
     * @OA\Tag(name="User")
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Route('/api/users', name: 'create_user', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        /** @var User $manager */
        $manager = $this->getUser();

        if (null !== $manager) {
            $userInformation = json_decode($request->getContent(), true);
            ValidatorService::validateCreateUserArray($userInformation);

            if ($this->userService->ensureEmailExist($userInformation["email"])) {
                throw new BilemoException("This email is already linked to an user, you should try with an other email", Response::HTTP_NOT_ACCEPTABLE);
            }

            $this->userService->createUser($userInformation, $manager);
            $this->cache->invalidateTags(["usersCache"]);
            return new JsonResponse("User correctly added", Response::HTTP_CREATED, []);
        }
    }


    /**
     *   This method delete a user.
     *
     * @OA\Response(
     *     response=200,
     *     description="Return delete a user",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items()
     *     )
     * )
     * @OA\Parameter(
     *     name="id",
     *     in="path",
     *     description="The identifiant of a user",
     *     @OA\Schema(type="int")
     * )
     * @OA\Tag(name="User")
     * @param int $id
     * @return JsonResponse
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Route('/api/users/{id}', name: 'delete_user', methods: ['DELETE'])]
    public function deleteUser(int $id): JsonResponse
    {
        Assert::integer($id, "The product Id must be an integer !");

        $this->userService->deleteUser($id);
        $this->cache->invalidateTags(["usersCache"]);
        return new JsonResponse("User correctly deleted", Response::HTTP_OK, []);
    }
}
