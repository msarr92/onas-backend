<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('ticket.{ticketId}', function ($user, $ticketId) {
    return true; // tu peux sécuriser plus tard
});
