<?php

namespace App\Http\Response;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success($data = null, $message = null, $status = 200, $headers = [], $options = 0, $cookie=null)
    {
        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message
        ];
    
        $jsonResponse = response()->json($response, $status, $headers, $options);
    
        if ($cookie) {
            $jsonResponse->withCookie($cookie);
        }
    
        return $jsonResponse;
    }

    public static function error($message = 'An error occurred', $status = 400, $errors=[], $headers = [], $options = 0)
    {
        $response = [
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ];

        return response()->json($response, $status, $headers, $options);
    }

    public static function successError($message = 'An error occurred', $status = 200, $errors=[], $headers = [], $options = 0)
    {
        $response = [
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ];

        return new static($response, $status, $headers, $options);
    }
}
