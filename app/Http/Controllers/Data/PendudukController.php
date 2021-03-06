<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Imports\ImporPenduduk;
use App\Models\DataDesa;
use App\Models\Penduduk;
use Doctrine\DBAL\Query\QueryException;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Yajra\DataTables\DataTables;
use Barryvdh\Debugbar\Facade as Debugbar;

use ZipArchive;

use function back;
use function compact;
use function config;
use function convert_born_date_to_age;
use function redirect;
use function request;
use function route;
use function strtolower;
use function substr;
use function ucwords;
use function view;

class PendudukController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Penduduk $penduduk)
    {
        $page_title       = 'Penduduk';
        $page_description = 'Data Penduduk';
        $list_desa        = DataDesa::get();

        return view('data.penduduk.index', compact('page_title', 'page_description', 'list_desa'));
    }

    /**
     * Return datatable Data Penduduk.
     *
     * @param Request $request
     * @return DataTables
     */
    public function getPenduduk(Request $request)
    {
        $desa = $request->input('desa');

        $query = DB::table('das_penduduk')
            ->leftJoin('das_data_desa', 'das_penduduk.desa_id', '=', 'das_data_desa.desa_id')
            ->leftJoin('ref_pendidikan_kk', 'das_penduduk.pendidikan_kk_id', '=', 'ref_pendidikan_kk.id')
            ->leftJoin('ref_kawin', 'das_penduduk.status_kawin', '=', 'ref_kawin.id')
            ->leftJoin('ref_pekerjaan', 'das_penduduk.pekerjaan_id', '=', 'ref_pekerjaan.id')
            ->select([
                'das_penduduk.id',
                'das_penduduk.foto',
                'das_penduduk.nik',
                'das_penduduk.nama',
                'das_penduduk.no_kk',
                'das_penduduk.alamat',
                'das_data_desa.nama as nama_desa',
                'ref_pendidikan_kk.nama as pendidikan',
                'das_penduduk.tanggal_lahir',
                'ref_kawin.nama as status_kawin',
                'ref_pekerjaan.nama as pekerjaan',
            ])
            ->when($desa, function ($query) use ($desa) {
                return $desa === 'ALL'
                    ? $query
                    : $query->where('das_data_desa.desa_id', $desa);
            })
            ->where('status_dasar', 1);

        return DataTables::of($query)
            ->addColumn('action', function ($row) {
                $edit_url   = route('data.penduduk.edit', $row->id);
                $delete_url = route('data.penduduk.destroy', $row->id);

                $data['edit_url']   = $edit_url;
                $data['delete_url'] = $delete_url;

                return view('forms.action', $data);
            })
            ->addColumn('tanggal_lahir', function ($row) {
                return convert_born_date_to_age($row->tanggal_lahir);
            })->make();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(Penduduk $penduduk)
    {
        $page_title       = 'Tambah';
        $page_description = 'Tambah Data Penduduk';

        return view('data.penduduk.create', compact('page_title', 'page_description'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        // Save Request
        try {
            $penduduk                = new Penduduk($request->all());
            $penduduk->id_rtm        = 0;
            $penduduk->rtm_level     = 0;
            $penduduk->pendidikan_id = 0;
            $penduduk->id_cluster    = 0;
            $penduduk->status_dasar  = 1;
            $penduduk->kecamatan_id  = config('app.default_profile');
            $penduduk->provinsi_id   = substr($penduduk->kecamatan_id, 0, 2);
            $penduduk->kabupaten_id  = substr($penduduk->kecamatan_id, 0, 5);

            request()->validate([
                'nama'                 => 'required',
                'nik'                  => 'required',
                'kk_level'             => 'required',
                'sex'                  => 'required',
                'tempat_lahir'         => 'required',
                'tanggal_lahir'        => 'required',
                'agama_id'             => 'required',
                'pendidikan_kk_id'     => 'required',
                'pendidikan_sedang_id' => 'required',
                'pekerjaan_id'         => 'required',
                'status_kawin'         => 'required',
                'warga_negara_id'      => 'required',
            ]);

            if ($request->hasFile('foto')) {
                $file     = $request->file('foto');
                $fileName = $file->getClientOriginalName();
                $request->file('foto')->move("storage/penduduk/foto/", $fileName);
                $penduduk->foto = $fileName;
            }

            $penduduk->save();
            return redirect()->route('data.penduduk.index')->with('success', 'Penduduk berhasil disimpan!');
        } catch (Exception $e) {
            return back()->withInput()->with('error', 'Penduduk gagal disimpan!');
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param Penduduk $penduduk
     * @return Response
     */
    public function edit($id)
    {
        $penduduk = Penduduk::findOrFail($id);
        if ($penduduk->foto == '') {
            $penduduk->file_struktur_organisasi = 'http://placehold.it/120x150';
        }
        $page_title       = 'Ubah';
        $page_description = 'Ubah Penduduk: ' . ucwords(strtolower($penduduk->nama));

        return view('data.penduduk.edit', compact('page_title', 'page_description', 'penduduk'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(Request $request, $id)
    {
        // Save Request
        try {
            $penduduk = Penduduk::where('id', $id)->first();
            $penduduk->fill($request->all());

            request()->validate([
                'nama'                 => 'required',
                'nik'                  => 'required',
                'kk_level'             => 'required',
                'sex'                  => 'required',
                'tempat_lahir'         => 'required',
                'tanggal_lahir'        => 'required',
                'agama_id'             => 'required',
                'pendidikan_kk_id'     => 'required',
                'pendidikan_sedang_id' => 'required',
                'pekerjaan_id'         => 'required',
                'status_kawin'         => 'required',
                'warga_negara_id'      => 'required',
                'foto'                 => 'image|mimes:png,bmp,gif,jpg,jpeg|max:1024',
            ]);

            if ($request->file('foto') == "") {
                $penduduk->foto = $penduduk->foto;
            } else {
                $file     = $request->file('foto');
                $fileName = $file->getClientOriginalName();
                $request->file('foto')->move("storage/penduduk/foto/", $fileName);
                $penduduk->foto = $fileName;
            }

            $penduduk->update();

            return redirect()->route('data.penduduk.index')->with('success', 'Penduduk berhasil disimpan!');
        } catch (QueryException $e) {
            return back()->withInput()->with('error', 'Penduduk gagal disimpan!');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy($id)
    {
        try {
            Penduduk::findOrFail($id)->delete();

            return redirect()->route('data.penduduk.index')->with('success', 'Penduduk sukses dihapus!');
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->route('data.penduduk.index')->with('error', 'Penduduk gagal dihapus!');
        }
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function import()
    {
        $page_title       = 'Impor';
        $page_description = 'Impor Data Penduduk';

        $list_desa = DB::table('das_data_desa')->select('*')->where('kecamatan_id', '=', config('app.default_profile'))->get();
        return view('data.penduduk.import', compact('page_title', 'page_description', 'list_desa'));
    }

    /**
     * Impor data penduduk dari file Excel.
     * Kalau penduduk sudah ada (berdasarkan NIK), update dengan data yg diimpor
     *
     * @return Response
     */
    public function importExcel(Request $request)
    {
        $this->validate($request, [
            'file' => 'file|mimes:zip|max:51200',
        ]);

        try {
            // Upload file zip temporary.
            $file = $request->file('file');
            $file->storeAs('temp', $name = $file->getClientOriginalName());

            // Temporary path file
            $path = storage_path("app/temp/{$name}");
            $extract = storage_path('app/public/penduduk/foto/');

            // Ekstrak file
            $zip = new ZipArchive;
            $zip->open($path);
            $zip->extractTo($extract);
            $zip->close();

            // Proses impor excell
            (new ImporPenduduk($request->all()))
                ->queue($extract . $excellName = Str::replaceLast('zip', 'xlsx', $name));
        } catch (Exception $e) {
            return back()->with('error', 'Import data gagal. ' . $e->getMessage());
        }

        // Hapus folder temp ketika sudah selesai
        Storage::deleteDirectory('temp');
        // Hapus file excell temp ketika sudah selesai
        Storage::disk('public')->delete('penduduk/foto/' . $excellName);

        return back()->with('success', 'Import data sukses.');
    }
}
