<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Admin cleanup removal. Deleting a card, order, or customer also removes the rows and
 * stored images that hang off it so no orphan records keep counting towards the dashboard.
 */
class AdminCleanupService
{
    /**
     * @return array{invitation_deleted: bool}
     */
    public function deleteOrder(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $invitation = $order->invitation;
            // A card can hold several attempts, so it only goes when this was its last order.
            $remainingOrders = $invitation
                ? $invitation->orders()->whereKeyNot($order->getKey())->count()
                : 0;

            $order->delete();

            $deleted = false;
            if ($invitation && $remainingOrders === 0) {
                $this->deleteInvitation($invitation);
                $deleted = true;
            }

            return ['invitation_deleted' => $deleted];
        });
    }

    /**
     * @return array{orders: int}
     */
    public function deleteInvitation(Invitation $invitation): array
    {
        return DB::transaction(function () use ($invitation): array {
            $orders = $invitation->orders()->count();

            $this->deletePhotobookFiles($invitation);
            // Orders, RSVP replies, wishes, photobook photos, and likes cascade from the card.
            $invitation->delete();

            return ['orders' => $orders];
        });
    }

    /**
     * @return array{orders: int, invitations: int, account_deleted: bool}
     */
    public function deleteCustomer(Customer $customer, bool $deleteAccount = false): array
    {
        return DB::transaction(function () use ($customer, $deleteAccount): array {
            $orders = 0;
            $invitations = 0;

            foreach ($customer->invitations()->get() as $invitation) {
                $orders += $this->deleteInvitation($invitation)['orders'];
                $invitations++;
            }

            // Orders pointing at another customer's card are removed without touching that card.
            $orders += $customer->orders()->count();
            $customer->orders()->delete();

            $accountDeleted = false;
            $user = $deleteAccount ? $customer->user : null;
            // Admin accounts are never removed from a customer cleanup.
            if ($user instanceof User && ! $user->is_admin) {
                // Cards created under the account but not tied to the customer row go too.
                foreach ($user->invitations()->get() as $invitation) {
                    $orders += $this->deleteInvitation($invitation)['orders'];
                    $invitations++;
                }
                // The user row cascades the remaining account data (orders, social logins).
                $user->delete();
                $accountDeleted = true;
            }

            $customer->delete();

            return ['orders' => $orders, 'invitations' => $invitations, 'account_deleted' => $accountDeleted];
        });
    }

    private function deletePhotobookFiles(Invitation $invitation): void
    {
        $paths = $invitation->photobookPhotos()->pluck('image_path')->all();

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }

        Storage::disk('public')->deleteDirectory('photobook/'.$invitation->getKey());
    }
}
