<html>
<head>
    <title>{{__('Check Updates Settings')}}</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
</head>

<body>
<div class="col-lg-12 col-ml-12 padding-bottom-30">
    <div class="row">
        <div class="col-3"></div>
        <div class="col-6 mt-5">
            @include('XgApiClient::partials.message')
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title">{{__("Check Updates Settings")}}</h4>

                    @php
                        $phpVCompare = version_compare(number_format((float) PHP_VERSION, 1), $phpVersionReq == 8 ? '8.0' : $phpVersionReq, '>=');
                        $mysqlServerVersion = \Illuminate\Support\Facades\DB::select('select version()')[0]->{'version()'};
                        $mysqlVCompare = version_compare(number_format((float) $mysqlServerVersion, 1), $mysqlVersionReq, '<=');

                        if ($extensions) {
                            foreach (explode(',', str_replace(' ','', strtolower($extensions))) as $extension) {

                                $extensionReq = extension_check($extension);
                            }
                        }
                    @endphp

                    @if(!$latestVersion)
                        <div class="text-success">{{ $msg }}</div>
                    @elseif(($phpVCompare === false || $mysqlVCompare === false) && $extensionReq === false)
                        <div class="text-danger">Your server doesn't have required software version installed. Required:
                            Php {{ $phpVersionReq == 8 ? '8.0' : $phpVersionReq }}, Mysql {{ $mysqlVersionReq }} /
                            Extensions: {{ $extensions }} etc
                        </div>
                    @else
                        <div class="card text-center">
                            <div class="card-header bg-transparent text-success">
                                Please backup your database & script files before upgrading.
                            </div>
                            <div class="card-body">
                                <h5 class="card-title">New Version({{ $latestVersion }}) is Available
                                    for {{ $productName }}!</h5>
                                <p class="card-text">With supporting text below as a natural lead-in to additional
                                    content.</p>
                                <a href="{{ route('update.download', [$productUid, $isTenant]) }}" onclick="event.preventDefault();
                                                         document.getElementById('productDownloadUpdate').submit();"
                                   class="btn btn-warning">Download & Update</a>

                                <form id="productDownloadUpdate"
                                      action="{{ route('update.download', [$productUid, $isTenant]) }}" method="post"
                                      class="d-none">
                                    @csrf
                                    @method('POST')
                                </form>
                            </div>

                            <p> {{ $changelog }}</p>
                            <div class="card-footer text-muted">
                                {{ $daysDiff }} days ago
                            </div>
                        </div>
                    @endif

                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
