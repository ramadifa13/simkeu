<?php

namespace App\Actions\Members;

use App\Models\Anggota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UpdateMemberAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Anggota $member, array $attributes): Anggota
    {
        return DB::transaction(function () use ($attributes, $member): Anggota {
            $documents = ['foto_ktp', 'foto_kk', 'foto_ktp_pasangan'];
            $oldDocuments = [];

            $member->fill(Arr::except($attributes, $documents));
            $member->kode_anggota_jaminan = $attributes['kode_anggota_jaminan'] ?? null;

            foreach ($documents as $documentField) {
                if (! isset($attributes[$documentField])) {
                    continue;
                }

                /** @var UploadedFile $document */
                $document = $attributes[$documentField];
                $oldDocuments[] = $member->{$documentField};
                $member->{$documentField} = $document->store("anggota/{$member->id}", 'local');
            }

            $member->save();
            $member->user?->update([
                'username' => $member->kode_anggota,
                'nama_lengkap' => $member->nama_lengkap,
                'email' => $member->email,
                'telepon' => $member->telepon,
            ]);

            DB::afterCommit(function () use ($oldDocuments): void {
                foreach (array_filter($oldDocuments) as $oldDocument) {
                    if (str_starts_with((string) $oldDocument, 'anggota/')) {
                        Storage::disk('local')->delete($oldDocument);
                    }
                }
            });

            return $member;
        });
    }
}
