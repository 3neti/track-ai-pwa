<?php

namespace App\Policies;

use App\Models\Upload;
use App\Models\User;

class UploadPolicy
{
    /**
     * Determine whether the user can view any uploads.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the upload.
     */
    public function view(User $user, Upload $upload): bool
    {
        // User owns the upload or is admin
        return $user->id === $upload->user_id || $user->role === 'admin';
    }

    /**
     * Determine whether the user can create uploads.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the upload.
     * Note: Editability checks (locked, project closed) are handled in the controller
     * to return appropriate 423 status codes.
     */
    public function update(User $user, Upload $upload): bool
    {
        // Must own or be admin
        return $user->id === $upload->user_id || $user->role === 'admin';
    }

    /**
     * Determine whether the user can delete the upload.
     * Note: Deletability checks are handled in the controller.
     */
    public function delete(User $user, Upload $upload): bool
    {
        // Must own or be admin
        return $user->id === $upload->user_id || $user->role === 'admin';
    }

    /**
     * Determine whether the user can retry the upload.
     * Note: Retryability checks are handled in the controller.
     */
    public function retry(User $user, Upload $upload): bool
    {
        // Must own or be admin
        return $user->id === $upload->user_id || $user->role === 'admin';
    }
}
