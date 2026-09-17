<?php

namespace App\Controllers\Alevel;

use App\Controllers\BaseController;
use App\Models\AlevelCombinationModel;
use App\Models\AlevelCombinationSubjectModel;
use App\Models\StudentModel;
use App\Models\AlevelSubjectMarksModel;
use App\Models\StudentAlevelCombinationModel;
use App\Models\ExamModel;
use App\Models\SessionModel;
use CodeIgniter\API\ResponseTrait;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class BulkMarksUploadController extends BaseController
{
    use ResponseTrait;

    protected $alevelMarksModel;
    protected $alevelCombinationModel;
    protected $alevelCombinationSubjectModel;
    protected $studentModel;
    protected $studentAlevelCombinationModel;
    protected $examModel;
    protected $sessionModel;
    protected $format = 'json';

    public function __construct()
    {
        $this->alevelMarksModel = new AlevelSubjectMarksModel();
        $this->alevelCombinationModel = new AlevelCombinationModel();
        $this->alevelCombinationSubjectModel = new AlevelCombinationSubjectModel();
        $this->studentModel = new StudentModel();
        $this->studentAlevelCombinationModel = new StudentAlevelCombinationModel();
        $this->examModel = new ExamModel();
        $this->sessionModel = new SessionModel();
    }

    public function index()
    {
        try {
            $data = [
                'combinations' => $this->alevelCombinationModel->where('is_active', 'yes')->findAll(),
                'subjects' => $this->alevelCombinationSubjectModel->where('is_active', 'yes')->findAll(),
                'students' => $this->studentModel->where('is_active', 'yes')->findAll(),
                'sessions' => $this->sessionModel->where('is_active', 'yes')->findAll(),
                'exams' => [],
                'classes' => [],
            ];

            $currentSession = $this->sessionModel->getCurrentSession();
            if ($currentSession) {
                $data['current_session'] = $currentSession;
                $data['exams'] = $this->examModel
                    ->where('session_id', $currentSession['id'])
                    ->where('is_active', 'yes')
                    ->findAll();
                
                $db = \Config\Database::connect('default');
                $data['classes'] = $db->table('classes c')
                    ->select('c.id, c.class')
                    ->join('tz_student_alevel_combinations sac', 'c.id = sac.class_id')
                    ->where([
                        'sac.session_id' => $currentSession['id'],
                        'sac.is_active' => 'yes',
                        'c.is_active' => 'yes'
                    ])
                    ->groupBy('c.id')
                    ->get()
                    ->getResultArray();
            }

            return view('alevel/BulkMarksUpload', $data);
        } catch (\Exception $e) {
            log_message('error', '[BulkMarksUploadController.index] Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to load bulk upload page: ' . $e->getMessage());
        }
    }

    public function downloadTemplate()
    {
        try {
            $examId = $this->request->getGet('exam_id');
            $classId = $this->request->getGet('class_id');
            $sessionId = $this->request->getGet('session_id');
            $combinationId = $this->request->getGet('combination_id');

            if (!$examId || !$classId || !$sessionId || !$combinationId) {
                throw new \Exception('Missing required parameters for template download');
            }

            // Fetch students
            $students = $this->studentModel
                ->select('students.id as student_id, students.firstname, students.middlename, students.lastname, classes.class')
                ->join('student_session', 'student_session.student_id = students.id')
                ->join('classes', 'classes.id = student_session.class_id')
                ->join('tz_student_alevel_combinations sac', 'sac.class_id = student_session.class_id AND sac.session_id = student_session.session_id AND (sac.section_id = student_session.section_id OR sac.section_id IS NULL)')
                ->join('tz_alevel_exam_combinations aec', "aec.combination_id = sac.combination_id AND aec.class_id = student_session.class_id AND aec.session_id = student_session.session_id AND aec.exam_id = '" . $examId . "'")
                ->where([
                    'student_session.session_id' => $sessionId,
                    'student_session.class_id' => $classId,
                    'student_session.is_active' => 'yes',
                    'students.is_active' => 'yes',
                    'sac.combination_id' => $combinationId,
                    'sac.is_active' => 'yes',
                    'aec.is_active' => 'yes'
                ])
                ->findAll();

            if (empty($students)) {
                throw new \Exception('No students found for the selected criteria');
            }

            // Fetch subjects
            $db = \Config\Database::connect('default');
            $subjects = $db->table('tz_alevel_combination_subjects')
                ->select('id, subject_name, subject_type')
                ->where([
                    'combination_id' => $combinationId,
                    'is_active' => 'yes'
                ])
                ->get()
                ->getResultArray();

            if (empty($subjects)) {
                throw new \Exception('No subjects found for the selected combination');
            }

            // Create Excel spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Marks Template');

            // Set headers
            $sheet->setCellValue('A1', 'Student ID');
            $sheet->setCellValue('B1', 'Student Name');
            $column = 'C';
            foreach ($subjects as $subject) {
                $sheet->setCellValue($column . '1', $subject['subject_name'] . ' (ID: ' . $subject['id'] . ')');
                $column++;
            }

            // Style headers
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4AE54A']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ];
            $sheet->getStyle('A1:' . $column . '1')->applyFromArray($headerStyle);

            // Populate data
            $row = 2;
            foreach ($students as $student) {
                $sheet->setCellValue('A' . $row, $student['student_id']);
                $sheet->setCellValue('B' . $row, $student['firstname'] . ' ' . $student['lastname']);
                $row++;
            }

            // Auto-size columns
            foreach (range('A', $column) as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Set content type and headers for download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Marks_Template_' . date('Ymd_His') . '.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        } catch (\Exception $e) {
            log_message('error', '[BulkMarksUploadController.downloadTemplate] Error: ' . $e->getMessage());
            return $this->respond([
                'status' => 'error',
                'message' => 'Failed to generate template: ' . $e->getMessage()
            ], 500);
        }
    }

    public function uploadMarks()
    {
        log_message('debug', '[BulkMarksUploadController.uploadMarks] Request received');
        try {
            $examId = $this->request->getPost('exam_id');
            $classId = $this->request->getPost('class_id');
            $sessionId = $this->request->getPost('session_id');
            $combinationId = $this->request->getPost('combination_id');
            $file = $this->request->getFile('marks_file');

            if (!$examId || !$classId || !$sessionId || !$combinationId || !$file) {
                throw new \Exception('Missing required parameters or file for upload');
            }

            if (!$file->isValid() || $file->getExtension() !== 'xlsx') {
                throw new \Exception('Invalid file format. Please upload an XLSX file.');
            }

            // Validate exam allocation
            $db = \Config\Database::connect('default');
            $examAllocation = $db->table('tz_alevel_exam_combinations')
                ->where([
                    'exam_id' => $examId,
                    'class_id' => $classId,
                    'combination_id' => $combinationId,
                    'session_id' => $sessionId,
                    'is_active' => 'yes'
                ])
                ->countAllResults();

            if ($examAllocation === 0) {
                throw new \Exception('Exam is not allocated to this class and combination');
            }

            // Validate subjects
            $validSubjects = $db->table('tz_alevel_combination_subjects')
                ->select('id, subject_name')
                ->where([
                    'combination_id' => $combinationId,
                    'is_active' => 'yes'
                ])
                ->get()
                ->getResultArray();

            $validSubjectIds = array_column($validSubjects, 'id');
            $subjectNameMap = array_column($validSubjects, 'subject_name', 'id');

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

            if (empty($data) || count($data) < 2) {
                throw new \Exception('Excel file is empty or contains no data rows');
            }

            // Process headers to map subject columns
            $headers = $data[0];
            $subjectColumns = [];
            for ($i = 2; $i < count($headers); $i++) {
                if (!empty($headers[$i])) {
                    preg_match('/\(ID: ([a-f0-9\-]{36})\)/', $headers[$i], $matches);
                    if (isset($matches[1]) && in_array($matches[1], $validSubjectIds)) {
                        $subjectColumns[$i] = $matches[1];
                    }
                }
            }

            if (empty($subjectColumns)) {
                throw new \Exception('No valid subject columns found in the Excel file');
            }

            // Process data rows
            $marksData = [];
            $errors = [];
            $consecutiveEmptyRows = 0;
            $maxConsecutiveEmptyRows = 2; // Stop after just 2 consecutive empty rows to be more strict
            for ($row = 1; $row < count($data); $row++) {
                $studentId = trim($data[$row][0]); // Trim to remove any spaces
                if (empty($studentId) || strlen($studentId) != 36) {
                    $consecutiveEmptyRows++;
                    if ($consecutiveEmptyRows >= $maxConsecutiveEmptyRows) {
                        log_message('debug', "[BulkMarksUploadController.uploadMarks] Stopping processing at Row $row due to consecutive empty rows.");
                        break; // Stop processing immediately after 2 empty rows
                    }
                    continue; // Skip empty rows without logging as error
                }
                $consecutiveEmptyRows = 0; // Reset counter if a valid row is found

                $marks = [];
                foreach ($subjectColumns as $colIndex => $subjectId) {
                    $mark = $data[$row][$colIndex];
                    if (!empty($mark) && (!is_numeric($mark) || $mark < 0 || $mark > 100)) {
                        $errors[] = "Row $row, Student ID $studentId: Invalid mark for subject " . ($subjectNameMap[$subjectId] ?? $subjectId) . " (Value: $mark)";
                        continue 2; // Skip this student entirely if any mark is invalid
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
            $this->alevelMarksModel->db->transStart();

            foreach ($marksData as $studentId => $marks) {
                // Delete existing marks for this student
                $this->alevelMarksModel->where([
                    'exam_id' => $examId,
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'session_id' => $sessionId,
                    'combination_id' => $combinationId
                ])->delete();

                // Insert new marks
                foreach ($marks as $subjectId => $mark) {
                    if ($mark === null) {
                        continue; // Skip null marks
                    }
                    $markData = [
                        'exam_id' => $examId,
                        'student_id' => $studentId,
                        'class_id' => $classId,
                        'session_id' => $sessionId,
                        'combination_id' => $combinationId,
                        'subject_id' => $subjectId,
                        'marks_obtained' => $mark
                    ];

                    if (!$this->alevelMarksModel->insert($markData)) {
                        throw new \Exception('Failed to save marks for student ID ' . $studentId . ': ' . implode(', ', $this->alevelMarksModel->errors()));
                    }
                }
            }

            $this->alevelMarksModel->db->transComplete();

            if ($this->alevelMarksModel->db->transStatus() === false) {
                throw new \RuntimeException('Failed to save marks in bulk');
            }

            // Redirect to the bulk upload page with a success message
            session()->setFlashdata('message', 'Marks uploaded successfully for ' . count($marksData) . ' students');
            return redirect()->to(base_url('alevel/marks/bulk'));
        } catch (\Exception $e) {
            log_message('error', '[BulkMarksUploadController.uploadMarks] Error: ' . $e->getMessage());
            return $this->respond([
                'status' => 'error',
                'message' => 'Failed to upload marks: ' . $e->getMessage()
            ], 500);
        }
    }
}
