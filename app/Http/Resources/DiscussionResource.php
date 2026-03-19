<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscussionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'ticket_id' => $this->ticket_id,
            'message'   => $this->message,
            'created_at'=> $this->created_at,
            'user'      => [
                'id'   => $this->utilisateur->id,
                'name' => $this->utilisateur->name,
                'role' => $this->utilisateur->profil
            ]
        ];
    }
}
