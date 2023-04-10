<html>
<head>
    <title>{{__('License Activation Settings')}}</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
</head>

<body>
    <div class="col-lg-12 col-ml-12 padding-bottom-30">
        <div class="row">
            <div class="col-12 mt-5">
                @include('XgApiClient::partials.message')
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title">{{__("License Activation Settings")}}</h4>

                        <form action="{{ route('license.activation.update') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="form-group">
                                <label for="item_purchase_key">{{__('Activation Key')}}</label>
                                <input type="text" name="product_activation_key"  class="form-control" value="" id="product_activation_key">
                            </div>
                            <div class="form-group">
                                <label for="item_purchase_key">{{__('Your Envato Username')}}</label>
                                <input type="text" name="client"  class="form-control" value="" id="client">
                            </div>

                            <button type="submit" class="btn btn-primary mt-4 pr-4 pl-4">{{__('Submit Information')}}</button>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
