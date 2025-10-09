<?php

declare(strict_types=1);

namespace Chamilo\CoreBundle\Controller\Api;

use Chamilo\CourseBundle\Entity\CDocument;
use Doctrine\ORM\EntityManagerInterface;
use DocumentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
class DocumentUsageAction extends AbstractController
{

    public function __invoke(EntityManagerInterface $entityManager): JsonResponse
    {
        error_log("DocumentUsageAction called");

        
        return new JsonResponse([
            'datasets' => [
                ['data' => [83, 14, 5]],
            ],
            'labels' => ['Course', 'Teacher', 'Available space'],
        ]);
    //     dump('DEBUG 0');
    //     $course = api_get_course_entity();
    //     $userId = api_get_user_id();

    //     $repo = $entityManager->getRepository(CDocument::class);
    //     $documents = $repo->getAllByCourse($course);

    //     $teacherQuota = 0;
    //     foreach ($documents as $doc) {
    //         if ($doc->getInsertUserId() == $userId && $doc->getFiletype() === 'file') {
    //             $teacherQuota += $doc->getSize();
    //         }
    //     }

    //     // $totalQuota = $this->documentManager::get_course_quota(api_get_course_int_id());
    //     $totalQuota = DocumentManager::get_course_quota(api_get_course_int_id());
    //     $usedQuota = DocumentManager::get_total_space();
    //     // $usedQuota = $this->documentManager::get_total_space();

    //     $availableQuota = $totalQuota - $usedQuota;

    //     return new JsonResponse([
    //         'datasets' => [[
    //             'data' => [
    //                 round($usedQuota / 1048576, 2),
    //                 round($teacherQuota / 1048576, 2),
    //                 round($availableQuota / 1048576, 2),
    //             ]
    //         ]],
    //         'labels' => [
    //             'Course used (MB)',
    //             'Teacher used (MB)',
    //             'Available (MB)',
    //         ],
    //     ]);
    // }
    }
}