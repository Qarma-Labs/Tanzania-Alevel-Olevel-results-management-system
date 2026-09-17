<?php

namespace App\Controllers;

use App\Models\ExamModel;
use App\Models\ExamClassModel;
use App\Models\StudentSessionModel;
use App\Models\ExamSubjectMarkModel;
use App\Models\SessionModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\RESTful\ResourceController;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class BulkExamMarksController extends ResourceController
{
    use ResponseTrait;

    protected $examModel;
    protected $examClassModel;
    protected $studentSessionModel;
    protected $examSubjectMarkModel;
    protected $sessionModel;
    protected $format = 'json';
    protected $db;

    public function __construct()
    {
        $this->examModel = new ExamModel();
        $this->examClassModel = new ExamClassModel();
        $this->studentSessionModel = new StudentSessionModel();
        $this->examSubjectMarkModel = new ExamSubjectMarkModel();
        $this->sessionModel = new SessionModel();
        $this->db = \Config\Database::connect('default');
    }

    public function downloadTemplate()
    {
        try {
            $examId = $this->request->getGet('exam_id');
            $classId = $this->request->getGet('class_id');
            $sessionId = $this->request->getGet('session_id');

            if (!$examId || !$classId || !$sessionId) {
                throw new \Exception('Missing required parameters');
            }

            // Get exam details
            $exam = $this->examModel->find($examId);
            $classBuilder = $this->db->table('classes');
            $this->applySchoolScopeToBuilder($classBuilder, 'classes');
            $class = $classBuilder->where('id', $classId)->get()->getRowArray();

            // Get students
            $students = $this->studentSessionModel
                ->select('students.id, students.firstname, students.lastname, students.roll_no')
                ->join('students', 'students.id = student_session.student_id')
                ->where([
                    'student_session.session_id' => $sessionId,
                    'student_session.class_id' => $classId,
                    'student_session.is_active' => 'yes',
                    'students.is_active' => 'yes'
                ])
                ->findAll();

            // Get subjects
            $subjectBuilder = $this->db->table('tz_exam_subjects');
            $this->applySchoolScopeToBuilder($subjectBuilder, 'tz_exam_subjects');
            $subjects = $subjectBuilder
                ->where('exam_id', $examId)
                ->get()
                ->getResultArray();

            if (empty($subjects)) {
                throw new \Exception('No subjects found for this exam');
            }

            // Create new Spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Set column widths
            $sheet->getColumnDimension('A')->setWidth(12);  // Student ID
            $sheet->getColumnDimension('B')->setWidth(30);  // Student Name
            $sheet->getColumnDimension('C')->setWidth(12);  // Roll No

            // Set title and headers
            $sheet->mergeCells('A1:C1');
            $sheet->setCellValue('A1', 'EXAM MARKS TEMPLATE');
            $sheet->mergeCells('A2:C2');
            $sheet->setCellValue('A2', $exam['exam_name'] . ' - ' . $class['class']);
            $sheet->mergeCells('A3:C3');
            $sheet->setCellValue('A3', 'Date: ' . date('Y-m-d'));

            // Style the header
            $headerStyle = [
                'font' => [
                    'bold' => true,
                    'size' => 14
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2EFDA']
                ]
            ];
            $sheet->getStyle('A1:C3')->applyFromArray($headerStyle);

            // Set column headers
            $sheet->setCellValue('A5', 'Student ID');
            $sheet->setCellValue('B5', 'Student Name');
            $sheet->setCellValue('C5', 'Roll No.');

            // Add subjects and their max marks
            $col = 'D';
            foreach ($subjects as $subject) {
                $sheet->getColumnDimension($col)->setWidth(15);
                $sheet->setCellValue($col . '5', $subject['subject_name']);
                $sheet->setCellValue($col . '6', 'Max: ' . $subject['max_marks']);
                $col++;
            }

            // Style the column headers
            $columnHeaderStyle = [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F2F2F2']
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN
                    ]
                ]
            ];
            $sheet->getStyle('A5:' . --$col . '6')->applyFromArray($columnHeaderStyle);

            // Add students
            $row = 7;
            foreach ($students as $student) {
                $sheet->setCellValue('A' . $row, $student['id']);
                $sheet->setCellValue('B' . $row, $student['firstname'] . ' ' . $student['lastname']);
                $sheet->setCellValue('C' . $row, $student['roll_no']);
                
                // Add empty cells for marks with validation
                $markCol = 'D';
                foreach ($subjects as $subject) {
                    // Add data validation for marks
                    $validation = $sheet->getCell($markCol . $row)->getDataValidation();
                    $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_WHOLE);
                    $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
                    $validation->setAllowBlank(true);
                    $validation->setFormula1(0);
                    $validation->setFormula2($subject['max_marks']);
                    $validation->setErrorTitle('Invalid Mark');
                    $validation->setError('Please enter a number between 0 and ' . $subject['max_marks']);
                    $validation->setPromptTitle('Allowed Mark');
                    $validation->setPrompt("Enter mark between 0 and {$subject['max_marks']}");
                    $validation->setShowErrorMessage(true);
                    $validation->setShowDropDown(true);
                    $markCol++;
                }
                $row++;
            }

            // Style the data rows
            $dataStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN
                    ]
                ]
            ];
            $sheet->getStyle('A7:' . $col . ($row-1))->applyFromArray($dataStyle);

            // Create Excel file
            $writer = new Xlsx($spreadsheet);
            $filename = "exam_marks_template_" . date('Y-m-d_His') . ".xlsx";

            // Set headers for download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            // Save to PHP output
            $writer->save('php://output');
            exit;

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    public function uploadMarks()
    {
        log_message('debug', '[BulkExamMarksController.uploadMarks] Request received');
        try {
            $examId = $this->request->getPost('exam_id');
            $classId = $this->request->getPost('class_id');
            $sessionId = $this->request->getPost('session_id');

            if (!$examId || !$classId || !$sessionId) {
                throw new \Exception('Missing required parameters for upload');
            }

            $file = $this->request->getFile('csv_file');

            if (!$file) {
                throw new \Exception('No file uploaded. Please select a file.');
            }

            if (!$file->isValid() || $file->getExtension() !== 'xlsx') {
                throw new \Exception('Invalid file format. Please upload an XLSX file.');
            }

            // Validate exam allocation
            $allocationBuilder = $this->db->table('tz_exam_classes');
            $this->applySchoolScopeToBuilder($allocationBuilder, 'tz_exam_classes');
            $examAllocation = $allocationBuilder
                ->where([
                    'exam_id' => $examId,
                    'class_id' => $classId,
                    'session_id' => $sessionId
                ])
                ->countAllResults();

            if ($examAllocation === 0) {
                throw new \Exception('Exam is not allocated to this class');
            }

            // Validate subjects
            $validSubjectBuilder = $this->db->table('tz_exam_subjects');
            $this->applySchoolScopeToBuilder($validSubjectBuilder, 'tz_exam_subjects');
            $validSubjects = $validSubjectBuilder
                ->select('id, subject_name, max_marks')
                ->where([
                    'exam_id' => $examId
                ])
                ->get()
                ->getResultArray();

            $validSubjectIds = array_column($validSubjects, 'id');
            $subjectNameMap = array_column($validSubjects, 'subject_name', 'id');
            $subjectMaxMarksMap = array_column($validSubjects, 'max_marks', 'id');

            if (empty($validSubjects)) {
                throw new \Exception('No subjects found for the selected exam');
            }

            // Hardening for known PhpSpreadsheet parser advisories (DoS/SSRF):
            // bound file size and sheet dimensions before parsing, and read
            // cached values only so embedded formulas are never evaluated.
            if ($file->getSize() > 5 * 1024 * 1024) {
                throw new \Exception('File too large. Maximum allowed size is 5 MB.');
            }

            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(true);

            foreach ($reader->listWorksheetInfo($file->getTempName()) as $sheetInfo) {
                if (($sheetInfo['totalRows'] ?? 0) > 10000 || ($sheetInfo['totalColumns'] ?? 0) > 200) {
                    throw new \Exception('Worksheet dimensions exceed the allowed limit.');
                }
            }

            // Read the uploaded Excel file
            $spreadsheet = $reader->load($file->getTempName());
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray();

            if (empty($data) || count($data) < 7) {
                throw new \Exception('Excel file is empty or contains no data rows');
            }

            // Process headers to map subject columns
            $headers = $data[4];
            $subjectColumns = [];
            for ($i = 3; $i < count($headers); $i++) {
                if (!empty($headers[$i])) {
                    foreach ($validSubjects as $subject) {
                        if ($headers[$i] === $subject['subject_name']) {
                            $subjectColumns[$i] = $subject['id'];
                            break;
                        }
                    }
                }
            }

            if (empty($subjectColumns)) {
                throw new \Exception('No valid subject columns found in the Excel file');
            }

            // Process data rows starting from row 7
            $marksData = [];
            $errors = [];
            $consecutiveEmptyRows = 0;
            $maxConsecutiveEmptyRows = 2;
            for ($row = 6; $row < count($data); $row++) {
                $studentId = trim($data[$row][0]);
                if (empty($studentId)) {
                    $consecutiveEmptyRows++;
                    if ($consecutiveEmptyRows >= $maxConsecutiveEmptyRows) {
                        log_message('debug', "[BulkExamMarksController.uploadMarks] Stopping processing at Row $row due to consecutive empty rows.");
                        break;
                    }
                    continue;
                }
                $consecutiveEmptyRows = 0;

                $marks = [];
                foreach ($subjectColumns as $colIndex => $subjectId) {
                    $mark = $data[$row][$colIndex];
                    if (!empty($mark) && (!is_numeric($mark) || $mark < 0 || $mark > $subjectMaxMarksMap[$subjectId])) {
                        $errors[] = "Row $row, Student ID $studentId: Invalid mark for subject " . ($subjectNameMap[$subjectId] ?? $subjectId) . " (Value: $mark, Max: " . $subjectMaxMarksMap[$subjectId] . ")";
                        continue 2;
                    }
                    $marks[$subjectId] = empty($mark) ? null : (int)$mark;
                }
                $marksData[$studentId] = $marks;
            }

            if (!empty($errors)) {
                throw new \Exception("Errors found in Excel file:\n" . implode("\n", array_slice($errors, 0, 10)) . (count($errors) > 10 ? "\n...and " . (count($errors) - 10) . " more errors" : ""));
            }

            if (empty($marksData)) {
                throw new \Exception('No valid data to process from the Excel file');
            }

            // Start transaction for bulk insert
            $this->db->transStart();

            foreach ($marksData as $studentId => $marks) {
                // Delete existing marks for this student
                $this->examSubjectMarkModel->where([
                    'exam_id' => $examId,
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'session_id' => $sessionId
                ])->delete();

                // Insert new marks
                foreach ($marks as $subjectId => $mark) {
                    if ($mark === null) {
                        continue;
                    }
                    $markData = [
                        'exam_id' => $examId,
                        'student_id' => $studentId,
                        'class_id' => $classId,
                        'session_id' => $sessionId,
                        'exam_subject_id' => $subjectId,
                        'marks_obtained' => $mark
                    ];

                    if (!$this->examSubjectMarkModel->insert($markData)) {
                        throw new \Exception('Failed to save marks for student ID ' . $studentId . ': ' . implode(', ', $this->examSubjectMarkModel->errors()));
                    }
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Failed to save marks in bulk');
            }

            // Return JSON response instead of redirect
            return $this->respond([
                'status' => 'success',
                'message' => 'Marks uploaded successfully for ' . count($marksData) . ' students'
            ]);
        } catch (\Exception $e) {
            log_message('error', '[BulkExamMarksController.uploadMarks] Error: ' . $e->getMessage());
            return $this->respond([
                'status' => 'error',
                'message' => 'Failed to upload marks: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index()
    {
        try {
            $data = [
                'title' => 'Bulk Upload Exam Marks',
                'sessions' => $this->sessionModel->where('is_active', 'yes')->findAll(), // Changed 'yes' to 'no'
                'exams' => [],
                'classes' => [],
            ];

            $currentSession = $this->sessionModel->getCurrentSession();
            if ($currentSession) {
                $data['current_session'] = $currentSession;
                $data['exams'] = $this->examModel
                    ->where('session_id', $currentSession['id'])
                    ->where('is_active', 'yes')  // Changed 'no' to 'yes'
                    ->findAll();
            }

            return view('exam/BulkUploadExamMarks', $data);
        } catch (\Exception $e) {
            return $this->fail('Failed to load bulk upload page: ' . $e->getMessage(), 500);
        }
    }

    public function getExams($sessionId)
    {
        try {
            $exams = $this->examModel
                ->where([
                    'session_id' => $sessionId,
                    'is_active' => 'yes'  // Changed 'no' to 'yes'
                ])
                ->findAll();

            return $this->respond([
                'status' => 'success',
                'data' => $exams
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getClasses($sessionId)
    {
        try {
            $db = \Config\Database::connect('default');
            $classesBuilder = $db->table('classes c');
            $this->applySchoolScopeToBuilder($classesBuilder, 'ec');
            $classes = $classesBuilder
                ->select('c.id, c.class')
                ->join('tz_exam_classes ec', 'c.id = ec.class_id')
                ->join('tz_exams e', 'e.id = ec.exam_id')
                ->where([
                    'e.session_id' => $sessionId,
                    'e.is_active' => 'yes',
                    'c.is_active' => 'yes'  // Changed 'no' to 'yes'
                ])
                ->groupBy('c.id')
                ->get()
                ->getResultArray();

            return $this->respond([
                'status' => 'success',
                'data' => $classes
            ]);
        } catch (\Exception $e) {
            return $this->respond([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}