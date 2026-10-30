<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Invoice;
use App\Models\StorageFile;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class InvoicePdfService
{
    public function __construct(
        private readonly BrandingAssetService $brandingAssets,
        private readonly FileStorageService $files,
    ) {}

    public function generate(Invoice $invoice, ?User $user = null): StorageFile
    {
        if ($user !== null) abort_unless($user->can('view',$invoice),404);
        $snapshot=$invoice->snapshot;
        abort_unless(is_array($snapshot) && isset($snapshot['totals'],$snapshot['lines']),422,
            'El documento legado carece de snapshot verificable.');
        $invoice->loadMissing(['tripPhase.project','contactPerson']);
        $pdf=Pdf::loadView('pdf.invoice',[
            'invoice'=>$invoice,'snapshot'=>$snapshot,
            'brand'=>$this->brandingAssets->colors(),
            'logoDataUri'=>$this->brandingAssets->logoDataUri(),
        ])->setOptions(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,
            'chroot'=>public_path('build')])
            ->setPaper('letter',count($snapshot['lines'])>35?'landscape':'portrait');
        $contents=$pdf->output();
        $disk=$this->files->activeDisk();
        $snapshotHash=hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR));
        $fileName=Str::slug($invoice->code?:'invoice').'-'.substr($snapshotHash,0,16).'.pdf';
        $objectKey='invoices/'.$invoice->id.'/'.$fileName;
        if (!Storage::disk($disk)->exists($objectKey)) Storage::disk($disk)->put($objectKey,$contents);
        $file=StorageFile::query()->firstOrCreate(['object_key'=>$objectKey],[
            'provider'=>$this->files->providerForDisk($disk),
            'bucket'=>$this->files->bucketForDisk($disk),
            'original_name'=>$fileName,'mime_type'=>'application/pdf',
            'size_bytes'=>strlen($contents),'checksum'=>hash('sha256',$contents),
            'public_url'=>null,
            'uploaded_by_id'=>$user?->id,
        ]);
        $invoice->forceFill(['pdf_file_id'=>$file->id])->save();
        return $file;
    }
}
