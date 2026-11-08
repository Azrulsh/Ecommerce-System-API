<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_id, 'rating' => $this->rating, 'comment' => $this->comment,
            'reviewer' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
