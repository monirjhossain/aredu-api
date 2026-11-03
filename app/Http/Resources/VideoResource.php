<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'cloudflare_uid' => $this->cloudflare_uid,
            'status' => $this->status,
            'duration_seconds' => $this->duration_seconds,
            'error_message' => $this->error_message,
            'cover_path' => $this->cover_path,
            'cover_url' => $this->cover_path ? url('storage/'.$this->cover_path) : null,
        ];
    }
}
