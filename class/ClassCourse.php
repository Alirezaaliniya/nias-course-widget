<?php
/**
 * Class ClassCourse
 * Handles course-related user data and operations
 */
class ClassCourse {
    /**
     * @var string User's name
     */
    private $nias_user_name;

    /**
     * @var string User's certificate information
     */
    private $nias_user_certificate;

    /**
     * @var int User's ID
     */
    private $nias_user_id;

    /**
     * @var int Product ID
     */
    private $nias_product_id;

    /**
     * @var int Order ID
     */
    private $nias_order_id;

    /**
     * ClassCourse constructor.
     */
    public function __construct() {
        $this->nias_user_name = '';
        $this->nias_user_certificate = '';
        $this->nias_user_id = 0;
        $this->nias_product_id = 0;
        $this->nias_order_id = 0;
    }

    /**
     * Set up user course data
     *
     * @param string $nias_user_name User's name
     * @param int $nias_user_id User's ID
     * @param string $nias_user_certificate User's certificate
     * @param int $nias_product_id Product ID
     * @param int $nias_order_id Order ID
     * @return bool
     */
    public function nias_setup_data($nias_user_name, $nias_user_id, $nias_user_certificate, $nias_product_id, $nias_order_id) {
        if (empty($nias_user_name) || empty($nias_user_id)) {
            return false;
        }

        $this->nias_user_name = sanitize_text_field($nias_user_name);
        $this->nias_user_id = intval($nias_user_id);
        $this->nias_user_certificate = sanitize_text_field($nias_user_certificate);
        $this->nias_product_id = intval($nias_product_id);
        $this->nias_order_id = intval($nias_order_id);

        return true;
    }

    /**
     * Get user name
     * @return string
     */
    public function get_user_name() {
        return $this->nias_user_name;
    }

    /**
     * Get user certificate
     * @return string
     */
    public function get_user_certificate() {
        return $this->nias_user_certificate;
    }

    /**
     * Get user ID
     * @return int
     */
    public function get_user_id() {
        return $this->nias_user_id;
    }

    /**
     * Get product ID
     * @return int
     */
    public function get_product_id() {
        return $this->nias_product_id;
    }

    /**
     * Get order ID
     * @return int
     */
    public function get_order_id() {
        return $this->nias_order_id;
    }

    /**
     * Check if the course data is valid
     * @return bool
     */
    public function is_valid() {
        return !empty($this->nias_user_name) && 
               !empty($this->nias_user_id) && 
               !empty($this->nias_product_id);
    }

    /**
     * Get course data as array
     * @return array
     */
    public function to_array() {
        return [
            'user_name' => $this->nias_user_name,
            'user_id' => $this->nias_user_id,
            'certificate' => $this->nias_user_certificate,
            'product_id' => $this->nias_product_id,
            'order_id' => $this->nias_order_id
        ];
    }
}

