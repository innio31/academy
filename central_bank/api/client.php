<?php
// /central_bank/api/client.php - API Client for Schools

class CentralBankAPI {
    private $base_url;
    private $api_key;
    
    public function __construct($base_url = 'https://acad.com.ng/central_bank/api/', $api_key = null) {
        $this->base_url = rtrim($base_url, '/') . '/';
        $this->api_key = $api_key ?? '33118913968799983134133712965617';
    }
    
    private function request($action, $params = []) {
        $url = $this->base_url . 'index.php?action=' . $action;
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-Key: ' . $this->api_key
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => "HTTP Error: $http_code"];
        }
        
        return json_decode($response, true);
    }
    
    public function getSubjects() {
        return $this->request('get_subjects');
    }
    
    public function getTopics($subject_id) {
        return $this->request('get_topics', ['subject_id' => $subject_id]);
    }
    
    public function getQuestions($subject_id, $topic_id = 0, $type = 'objective', $page = 1, $limit = 20, $school_id = 0) {
        return $this->request('get_questions', [
            'subject_id' => $subject_id,
            'topic_id' => $topic_id,
            'type' => $type,
            'page' => $page,
            'limit' => $limit,
            'school_id' => $school_id
        ]);
    }
    
    public function getQuestionCount($subject_id, $topic_id = 0) {
        return $this->request('get_question_count', [
            'subject_id' => $subject_id,
            'topic_id' => $topic_id
        ]);
    }
    
    public function getStats() {
        return $this->request('get_stats');
    }
}