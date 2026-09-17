<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// Remove index.php from URLs
$routes->setAutoRoute(false);
$routes->setDefaultNamespace("App\Controllers");

// =============================================================================
// Public Routes (No Authentication Required)
// =============================================================================
$routes->group("", [], function ($routes) {
    // Homepage
    $routes->get("/", "Home::index");

    // Public Student Results
    $routes->group(
        "public/results",
        ["namespace" => "App\Controllers"],
        function ($routes) {
            $routes->get("/", "StudenResultController::showStudentResultsPage");
            $routes->get("getExams", "StudenResultController::getExams");
            $routes->post(
                "getFilteredStudentResults",
                "StudenResultController::getFilteredStudentResults",
            );
            $routes->post(
                "getStudentSubjectMarks",
                "StudenResultController::getStudentSubjectMarks",
            );
            $routes->post(
                "getReportCard",
                "StudenResultController::getReportCard",
            );
        },
    );
});

// =============================================================================
// Authentication Routes (Custom)
// =============================================================================
$routes->group("", [], function ($routes) {
    $routes->get("login", "AuthController::loginForm");
    $routes->post("login", "AuthController::login");
    $routes->get("logout", "AuthController::logout");

    $routes->get("register", "AuthController::registerForm");
    $routes->post("register", "AuthController::register");
});

// =============================================================================
// Protected Routes (Require Authentication + known RBAC role)
// Outer guard allows admin, head_of_school and teacher. Sensitive routes add a
// stricter `role:admin,head_of_school` filter on top (both must pass).
// =============================================================================
$routes->group("", ["filter" => ["auth", "role:admin,head_of_school,teacher"]], function ($routes) {
    // -----------------------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------------------
    $routes->get("/", "DashboardController::index");
    $routes->get("dashboard", "DashboardController::index");

    // -----------------------------------------------------------------------------
    // Students (Old)
    // -----------------------------------------------------------------------------
    $routes->group("student", function ($routes) {
        $routes->get("/", "StudentController::index");
        $routes->get("fetchStudents", "StudentController::fetchStudents");
        $routes->get("getStudent/(:segment)", 'StudentController::getStudent/$1');
        $routes->get("getClasses", "StudentController::getClasses");
        $routes->get("getSections/(:segment)", 'StudentController::getSections/$1');
        $routes->get("getSessions", "StudentController::getSessions");
    });

    // -----------------------------------------------------------------------------
    // Student Management
    // -----------------------------------------------------------------------------
    $routes->group("students", ["namespace" => "App\Controllers"], function ($routes) {
        $routes->get("/", "StudentManagementController::index");
        $routes->get("create", "StudentManagementController::create");
        $routes->get("edit/(:segment)", "StudentManagementController::edit/$1");
        $routes->get("bulk-register", "StudentManagementController::bulkRegister");
        $routes->post("store", "StudentManagementController::store");
        $routes->post("store-bulk", "StudentManagementController::storeBulk");
        $routes->post("update/(:segment)", "StudentManagementController::update/$1");
        $routes->get("getStudents", "StudentManagementController::getStudents");
        $routes->post("delete/(:segment)", "StudentManagementController::delete/$1", ["filter" => "role:admin,head_of_school"]);
    });

    // -----------------------------------------------------------------------------
    // Class Management
    // -----------------------------------------------------------------------------
    $routes->group("classes", ["namespace" => "App\Controllers"], function ($routes) {
        $routes->get("/", "ClassManagementController::index");
        $routes->get("create", "ClassManagementController::create", ["filter" => "role:admin,head_of_school"]);
        $routes->get("edit/(:segment)", "ClassManagementController::edit/$1", ["filter" => "role:admin,head_of_school"]);
        $routes->post("store", "ClassManagementController::store", ["filter" => "role:admin,head_of_school"]);
        $routes->post("update/(:segment)", "ClassManagementController::update/$1", ["filter" => "role:admin,head_of_school"]);
        $routes->get("getClasses", "ClassManagementController::getClasses");
        $routes->post("delete/(:segment)", "ClassManagementController::delete/$1", ["filter" => "role:admin,head_of_school"]);
        $routes->delete("delete/(:segment)", "ClassManagementController::delete/$1", ["filter" => "role:admin,head_of_school"]);

        $routes->get("sections", "ClassManagementController::sections");
        $routes->get("sections/create", "ClassManagementController::createSection", ["filter" => "role:admin,head_of_school"]);
        $routes->get("sections/edit/(:segment)", "ClassManagementController::editSection/$1", ["filter" => "role:admin,head_of_school"]);
        $routes->post("sections/store", "ClassManagementController::storeSection", ["filter" => "role:admin,head_of_school"]);
        $routes->post("sections/update/(:segment)", "ClassManagementController::updateSection/$1", ["filter" => "role:admin,head_of_school"]);
        $routes->get("getSections", "ClassManagementController::getSections");
        $routes->post("sections/delete/(:segment)", "ClassManagementController::deleteSection/$1", ["filter" => "role:admin,head_of_school"]);

        $routes->get("allocations", "ClassManagementController::allocations");
        $routes->get("allocations/create", "ClassManagementController::createAllocation", ["filter" => "role:admin,head_of_school"]);
        $routes->get("allocations/edit/(:segment)", "ClassManagementController::editAllocation/$1", ["filter" => "role:admin,head_of_school"]);
        $routes->post("allocations/store", "ClassManagementController::storeAllocation", ["filter" => "role:admin,head_of_school"]);
        $routes->post("allocations/update/(:segment)", "ClassManagementController::updateAllocation/$1", ["filter" => "role:admin,head_of_school"]);
        $routes->get("getAllocations", "ClassManagementController::getAllocations");
        $routes->post("allocations/delete/(:segment)", "ClassManagementController::deleteAllocation/$1", ["filter" => "role:admin,head_of_school"]);
    });

    // -----------------------------------------------------------------------------
    // Exams (O-Level and General)
    // -----------------------------------------------------------------------------
    $routes->group("exam", function ($routes) {
        // Exam Creation (admin / head of school only)
        $routes->get("/", "AddExamController::index");
        $routes->get("getSessions", "AddExamController::getSessions");
        $routes->post("store", "AddExamController::store", ["filter" => "role:admin,head_of_school"]);

        // Exam Subjects
        $routes->get("subjects", "AddExamSubjectController::index");
        $routes->get("subjects/(:segment)", 'AddExamSubjectController::index/$1');
        $routes->post(
            "subjects/store-batch",
            "AddExamSubjectController::storeBatch",
            ["filter" => "role:admin,head_of_school"],
        );
        $routes->post(
            "subjects/update/(:segment)",
            'AddExamSubjectController::update/$1',
            ["filter" => "role:admin,head_of_school"],
        );
        $routes->get(
            "subjects/list/(:segment)",
            'AddExamSubjectController::getExamSubjects/$1',
        );
        $routes->post(
            "subjects/delete/(:segment)",
            'AddExamSubjectController::delete/$1',
            ["filter" => "role:admin,head_of_school"],
        );

        // Exam Allocations
        $routes->get("allocation", "AllocationController::index");
        $routes->get(
            "allocation/exams/(:segment)",
            'AllocationController::getExamsBySession/$1',
        );
        $routes->get(
            "allocation/list/(:segment)",
            'AllocationController::getAllocations/$1',
        );
        $routes->post("allocation/store", "AllocationController::store", ["filter" => "role:admin,head_of_school"]);
        $routes->post(
            "allocation/delete/(:segment)/(:segment)",
            'AllocationController::deallocate/$1/$2',
            ["filter" => "role:admin,head_of_school"],
        );

        // Exam Marks (Individual)
        $routes->get("marks", "AddExamMarks::index");
        $routes->get("marks/exams/(:segment)", 'AddExamMarks::getExams/$1');
        $routes->get("marks/classes/(:segment)", 'AddExamMarks::getClasses/$1');
        $routes->get("marks/subjects", "AddExamMarks::getSubjects");
        $routes->get("marks/students", "AddExamMarks::getStudents");
        $routes->get(
            "marks/existing/(:segment)/(:segment)",
            'AddExamMarks::getExistingMarks/$1/$2',
        );
        $routes->post("marks/save", "AddExamMarks::saveMarks");

        // Exam Marks (Bulk)
        $routes->get("marks/bulk", "BulkExamMarksController::index");
        $routes->get(
            "marks/bulk/getExams/(:segment)",
            'BulkExamMarksController::getExams/$1',
        );
        $routes->get(
            "marks/bulk/getClasses/(:segment)",
            'BulkExamMarksController::getClasses/$1',
        );
        $routes->get(
            "marks/bulk/downloadTemplate",
            "BulkExamMarksController::downloadTemplate",
        );
        $routes->post(
            "marks/bulk/uploadMarks",
            "BulkExamMarksController::uploadMarks",
        );

        // View Exam Marks
        $routes->get("marks/view", "ViewExamMarksController::index");
        $routes->get(
            "marks/view/getExams",
            "ViewExamMarksController::getExams",
        );
        $routes->get(
            "marks/view/getExamClasses",
            "ViewExamMarksController::getExamClasses",
        );
        $routes->get(
            "marks/view/getStudentMarks",
            "ViewExamMarksController::getStudentMarks",
        );
        $routes->post(
            "marks/view/update/(:segment)",
            'ViewExamMarksController::update/$1',
        );
        $routes->post(
            "marks/view/delete/(:segment)",
            'ViewExamMarksController::delete/$1',
            ["filter" => "role:admin,head_of_school"],
        );
        $routes->post(
            "marks/view/updateAll",
            "ViewExamMarksController::updateAll",
        );
        $routes->post(
            "marks/view/deleteAll",
            "ViewExamMarksController::deleteAll",
            ["filter" => "role:admin,head_of_school"],
        );

        // View Exams
        $routes->get("view", "ViewExamController::index");
        $routes->get("view/getSessions", "ViewExamController::getSessions");
        $routes->get("view/getExams", "ViewExamController::getExams");
        $routes->post("view/update/(:segment)", 'ViewExamController::update/$1', ["filter" => "role:admin,head_of_school"]);
        $routes->post("view/delete/(:segment)", 'ViewExamController::delete/$1', ["filter" => "role:admin,head_of_school"]);
    });

    // -----------------------------------------------------------------------------
    // Results
    // -----------------------------------------------------------------------------
    $routes->group("results", ["namespace" => "App\Controllers"], function (
        $routes,
    ) {
        // Result Grading and Publishing (admin / head of school only)
        $routes->get("publish", "ResultGradingController::showPublishPage", ["filter" => "role:admin,head_of_school"]);
        $routes->post(
            "process-grades",
            "ResultGradingController::processGradeCalculation",
            ["filter" => "role:admin,head_of_school"],
        );
        $routes->get(
            "fetch-class-results",
            "ResultGradingController::fetchClassResults",
        );
        $routes->get(
            "fetch-class-exams",
            "ResultGradingController::fetchClassExams",
        );
        $routes->get("getExams", "ResultGradingController::getExams");
        $routes->get(
            "getSections/(:segment)",
            'ResultGradingController::getSections/$1',
        );
        $routes->get(
            "getExamsBySession/(:segment)",
            'ResultGradingController::getExamsBySession/$1',
        );

        // View Results
        $routes->get("view", "ViewResultsModel::showResultsPage");
        $routes->get("view/getExams", "ViewResultsModel::getExams");
        $routes->post(
            "view/getFilteredResults",
            "ViewResultsModel::getFilteredResults",
        );
        $routes->get(
            "view/fetchResults/(:segment)/(:segment)/(:segment)",
            'ViewResultsModel::fetchResults/$1/$2/$3',
        );
        $routes->post(
            "view/getStudentSubjectMarks",
            "ViewResultsModel::getStudentSubjectMarks",
        );
        $routes->post("view/downloadPDF", "PDFController::generateResultPDF");
        $routes->post(
            "view/downloadStudentPDF",
            "PDFController::generateResultPDF",
        );
    });

    // -----------------------------------------------------------------------------
    // A-Level Combinations & Marks
    // -----------------------------------------------------------------------------
    $routes->group(
        "alevel",
        ["namespace" => "App\Controllers\Alevel"],
        function ($routes) {
            // Combinations (admin / head of school only for writes)
            $routes->get("combinations", "AddAlevelController::index");
            $routes->post("combinations/store", "AddAlevelController::store", ["filter" => "role:admin,head_of_school"]);
            $routes->get(
                "combinations/edit/(:segment)",
                'AddAlevelController::edit/$1',
                ["filter" => "role:admin,head_of_school"],
            );
            $routes->post(
                "combinations/update/(:segment)",
                'AddAlevelController::update/$1',
                ["filter" => "role:admin,head_of_school"],
            );
            $routes->post(
                "combinations/delete/(:segment)",
                'AddAlevelController::delete/$1',
                ["filter" => "role:admin,head_of_school"],
            );

            // Subjects
            $routes->get("subjects", "AlevelSubjectsController::index");
            $routes->get("subjects/view", "AlevelSubjectsController::view");
            $routes->post("subjects/store", "AlevelSubjectsController::store", ["filter" => "role:admin,head_of_school"]);
            $routes->get(
                "subjects/edit/(:segment)",
                'AlevelSubjectsController::edit/$1',
                ["filter" => "role:admin,head_of_school"],
            );
            $routes->post(
                "subjects/update/(:segment)",
                'AlevelSubjectsController::update/$1',
                ["filter" => "role:admin,head_of_school"],
            );
            $routes->post(
                "subjects/delete/(:segment)",
                'AlevelSubjectsController::delete/$1',
                ["filter" => "role:admin,head_of_school"],
            );

            // Combination Allocations
            $routes->get(
                "allocations",
                "AllocationCombinationClasssController::create",
            );
            $routes->get(
                "allocations/view",
                "AllocationCombinationClasssController::index",
            );
            $routes->post(
                "allocations/store",
                "AllocationCombinationClasssController::store",
                ["filter" => "role:admin,head_of_school"],
            );
            $routes->get(
                "allocations/edit/(:segment)",
                'AllocationCombinationClasssController::edit/$1',
                ["filter" => "role:admin,head_of_school"],
            );
            $routes->post(
                "allocations/update/(:segment)",
                'AllocationCombinationClasssController::update/$1',
                ["filter" => "role:admin,head_of_school"],
            );
            $routes->get(
                "allocations/delete/(:segment)",
                'AllocationCombinationClasssController::delete/$1',
                ["filter" => "role:admin,head_of_school"],
            );
            $routes->get(
                "allocations/get-sections",
                "AllocationCombinationClasssController::getSections",
            );
            $routes->get(
                "allocations/get-classes-by-session/(:segment)",
                'AllocationCombinationClasssController::getClassesBySession/$1',
            );

            // Marks Entry
            $routes->get("marks", "AddAlevelMarksController::index");
            $routes->get(
                "marks/getExams/(:segment)",
                'AddAlevelMarksController::getExams/$1',
            );
            $routes->get(
                "marks/getClasses/(:segment)",
                'AddAlevelMarksController::getClasses/$1',
            );
            $routes->get(
                "marks/getCombinations/(:segment)/(:segment)",
                'AddAlevelMarksController::getCombinations/$1/$2',
            );
            $routes->get(
                "marks/getStudents",
                "AddAlevelMarksController::getStudents",
            );
            $routes->get(
                "marks/getSubjects",
                "AddAlevelMarksController::getSubjects",
            );
            $routes->get(
                "marks/getExistingMarks/(:segment)/(:segment)",
                'AddAlevelMarksController::getExistingMarks/$1/$2',
            );
            $routes->post("marks/save", "AddAlevelMarksController::saveMarks");

            // Bulk Marks Upload
            $routes->get("marks/bulk", "BulkMarksUploadController::index");
            $routes->get(
                "marks/bulk/downloadTemplate",
                "BulkMarksUploadController::downloadTemplate",
            );
            $routes->post(
                "marks/bulk/upload",
                "BulkMarksUploadController::uploadMarks",
            );

            // View, Update, and Delete Marks
            $routes->get("marks/view", "ViewAlevelMarksController::index");
            $routes->post("marks/view", "ViewAlevelMarksController::index");
            $routes->post("marks/update", "ViewAlevelMarksController::update");
            $routes->post("marks/delete", "ViewAlevelMarksController::delete", ["filter" => "role:admin,head_of_school"]);
            $routes->get(
                "marks/getExams/(:segment)",
                'ViewAlevelMarksController::getExams/$1',
            );
            $routes->get(
                "marks/getClasses/(:segment)",
                'ViewAlevelMarksController::getClasses/$1',
            );

            $routes->get("results/publish", "PublishAlevelResults::index", ["filter" => "role:admin,head_of_school"]);
            $routes->get(
                "results/getExams/(:segment)",
                'PublishAlevelResults::getExams/$1',
            );
            $routes->get(
                "results/getClasses/(:segment)",
                'PublishAlevelResults::getClasses/$1',
            );
            $routes->get(
                "results/getCombinations/(:segment)/(:segment)",
                'PublishAlevelResults::getCombinations/$1/$2',
            );
            $routes->get(
                "results/calculate",
                "PublishAlevelResults::calculateResults",
                ["filter" => "role:admin,head_of_school"],
            );

            // Routes for viewing A-Level results
            $routes->get(
                "results/view",
                "ViewAlevelResultsController::showResultsPage",
            );
            $routes->get(
                "results/view/getExams",
                "ViewAlevelResultsController::getExams",
            );
            $routes->post(
                "results/view/getFilteredResults",
                "ViewAlevelResultsController::getFilteredResults",
            );
            $routes->post(
                "results/view/getStudentSubjectMarks",
                "ViewAlevelResultsController::getStudentSubjectMarks",
            );
            $routes->post(
                "results/view/generateClassResultsPDF",
                "ViewAlevelResultsController::generateClassResultsPDF",
            );
            $routes->post(
                "results/view/downloadStudentResultPDF",
                "ViewAlevelResultsController::downloadStudentResultPDF",
            );
        },
    );

    // -----------------------------------------------------------------------------
    // A-Level Exam Allocations
    // -----------------------------------------------------------------------------
    $routes->group(
        "alevel",
        ["namespace" => "App\Controllers\Alevel"],
        function ($routes) {
            // Exam Allocations (admin / head of school only for writes)
            $routes->get("allocate-exams", "AlllocateAlevelExam::index");
            $routes->get(
                "allocate-exams/get-exams/(:segment)",
                'AlllocateAlevelExam::getExamsBySession/$1',
            );
            $routes->get(
                "allocate-exams/get-classes/(:segment)",
                'AlllocateAlevelExam::getClassesBySession/$1',
            );
            $routes->post("allocate-exams/store", "AlllocateAlevelExam::store", ["filter" => "role:admin,head_of_school"]);

            // View Exam Allocations
            $routes->get("view-exams", "ViewAlevelExams::index");
            $routes->get(
                "view-exams/get-allocations/(:segment)",
                'ViewAlevelExams::getAllocations/$1',
            );
            $routes->delete(
                "view-exams/deallocate/(:segment)/(:segment)",
                'ViewAlevelExams::deallocate/$1/$2',
                ["filter" => "role:admin,head_of_school"],
            );
        },
    );

    // -----------------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------------
    $routes->group("settings", ["namespace" => "App\Controllers"], function (
        $routes,
    ) {
        $routes->get("/", "SettingsController::index", ["filter" => "role:admin,head_of_school"]);
        $routes->get("create", "SettingsController::create", ["filter" => "role:admin,head_of_school"]);
        $routes->get("edit", "SettingsController::edit", ["filter" => "role:admin,head_of_school"]);
        $routes->get("view", "SettingsController::view", ["filter" => "role:admin,head_of_school"]);
        $routes->post("store", "SettingsController::store", ["filter" => "role:admin,head_of_school"]);
        $routes->post("update", "SettingsController::update", ["filter" => "role:admin,head_of_school"]);
        $routes->get("test", "SettingsController::test", ["filter" => "role:admin,head_of_school"]);
    });

    // -------------------------------------------------------------------------
    // Analytics
    // -------------------------------------------------------------------------
    $routes->get("analytics", "DataAnalyticsController::index");
    $routes->get("analytics/overview", "DataAnalyticsController::overview");
});

?>
